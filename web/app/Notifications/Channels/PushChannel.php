<?php

namespace App\Notifications\Channels;

use App\Models\Device;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ارسال پوش‌نوتیفیکیشن به اپ اندروید و iOS از طریق Firebase Cloud Messaging (HTTP v1).
 *
 * فعال‌سازی: FCM_ENABLED=true و مسیر فایل Service Account در FCM_CREDENTIALS
 * (از کنسول Firebase ← Project settings ← Service accounts دریافت می‌شود)
 */
class PushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush') || ! method_exists($notifiable, 'devices')) {
            return;
        }

        $tokens = $notifiable->devices()->whereIn('platform', ['android', 'ios'])->pluck('token');
        if ($tokens->isEmpty()) {
            return;
        }

        try {
            $credentials = $this->credentials();
            $accessToken = $this->accessToken($credentials);
        } catch (Throwable $e) {
            Log::warning('FCM not configured correctly: '.$e->getMessage());

            return;
        }

        $message = $notification->toPush($notifiable);
        $url = "https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send";

        foreach ($tokens as $token) {
            $response = Http::withToken($accessToken)->timeout(10)->post($url, [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $message['title'], 'body' => $message['body']],
                    'data' => $message['data'] ?? [],
                ],
            ]);

            // توکن منقضی یا حذف‌شده: از پایگاه داده پاک شود
            if (in_array($response->status(), [404, 410], true) || str_contains($response->body(), 'UNREGISTERED')) {
                Device::where('token', $token)->delete();
            }
        }
    }

    private function credentials(): array
    {
        $path = (string) config('services.fcm.credentials');
        $json = json_decode((string) @file_get_contents($path), true);
        if (! is_array($json) || empty($json['private_key']) || empty($json['client_email'])) {
            throw new \RuntimeException('Invalid FCM service account file');
        }

        return $json;
    }

    /** دریافت توکن OAuth2 با امضای JWT (بدون نیاز به کتابخانه اضافه) */
    private function accessToken(array $credentials): string
    {
        return Cache::remember('fcm:access-token', 3000, function () use ($credentials) {
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            openssl_sign("{$header}.{$claims}", $signature, $credentials['private_key'], 'sha256WithRSAEncryption');
            $jwt = "{$header}.{$claims}.".$this->base64Url($signature);

            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return (string) $response->throw()->json('access_token');
        });
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
