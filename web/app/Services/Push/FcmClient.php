<?php

namespace App\Services\Push;

use App\Support\Outbound;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * اتصال به Firebase Cloud Messaging (HTTP v1) بدون کتابخانه اضافه.
 *
 * اعتبارنامه: محتوای JSON حساب سرویس از پنل مدیریت (services.fcm.credentials_json)
 * یا مسیر همان فایل در ‎.env (FCM_CREDENTIALS).
 */
class FcmClient
{
    public function credentials(): array
    {
        $raw = config('services.fcm.credentials_json');
        if (is_array($raw)) {
            $json = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $json = json_decode($raw, true);
        } else {
            $path = (string) config('services.fcm.credentials');
            $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        }
        if (! is_array($json) || empty($json['private_key']) || empty($json['client_email']) || empty($json['project_id'])) {
            throw new RuntimeException('اعتبارنامه Firebase تنظیم نشده یا نامعتبر است.');
        }

        return $json;
    }

    /** توکن OAuth2 با امضای JWT (۵۰ دقیقه کش؛ با عوض شدن اعتبارنامه کلید کش هم عوض می‌شود) */
    public function accessToken(?array $credentials = null): string
    {
        $credentials ??= $this->credentials();
        $cacheKey = 'fcm:access-token:'.hash('sha256', $credentials['client_email'].'|'.$credentials['private_key']);

        return Cache::remember($cacheKey, 3000, function () use ($credentials) {
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            if (! @openssl_sign("{$header}.{$claims}", $signature, $credentials['private_key'], 'sha256WithRSAEncryption')) {
                throw new RuntimeException('کلید خصوصی Firebase نامعتبر است.');
            }
            $jwt = "{$header}.{$claims}.".$this->base64Url($signature);

            $response = $this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            $token = (string) $response->json('access_token');
            if (! $response->successful() || $token === '') {
                throw new RuntimeException('Firebase اعتبارنامه را نپذیرفت.');
            }

            return $token;
        });
    }

    /** @param array{title:string, body:string, data?:array, alarm?:bool} $message */
    public function send(string $deviceToken, array $message, ?array $credentials = null): Response
    {
        $credentials ??= $this->credentials();

        $payload = [
            'token' => $deviceToken,
            'notification' => ['title' => $message['title'], 'body' => $message['body']],
            'data' => array_map('strval', $message['data'] ?? []),
        ];
        if (! empty($message['alarm'])) {
            // هشدار و یادآور: اولویت بالا و صدای زنگ (کانال «alarms» در اپ اندروید)
            $payload['android'] = ['priority' => 'high', 'notification' => ['sound' => 'default', 'channel_id' => 'alarms']];
            $payload['apns'] = ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => ['sound' => 'default', 'interruption-level' => 'time-sensitive']]];
        }

        return $this->http()->withToken($this->accessToken($credentials))
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", ['message' => $payload]);
    }

    /** اگر سرور در ایران است، Firebase (گوگل) از پراکسی خروجی خارج در دسترس است */
    private function http(): PendingRequest
    {
        $request = Http::timeout(10);
        $proxy = Outbound::foreign();

        return $proxy !== null ? $request->withOptions(['proxy' => $proxy]) : $request;
    }

    /** بررسی اتصال برای پنل مدیریت */
    public function test(): string
    {
        $credentials = $this->credentials();
        $this->accessToken($credentials);

        return "اتصال به پروژه «{$credentials['project_id']}» برقرار است.";
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
