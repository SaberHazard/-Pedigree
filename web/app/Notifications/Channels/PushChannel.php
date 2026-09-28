<?php

namespace App\Notifications\Channels;

use App\Models\Device;
use App\Services\Push\FcmClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ارسال پوش‌نوتیفیکیشن به اپ اندروید و iOS از طریق Firebase Cloud Messaging (HTTP v1).
 *
 * فعال‌سازی: پنل مدیریت ← «تنظیمات و اتصال‌ها» ← اعلان روی گوشی
 * (یا FCM_ENABLED=true و FCM_CREDENTIALS در ‎.env)
 */
class PushChannel
{
    public function __construct(private readonly FcmClient $fcm) {}

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
            $credentials = $this->fcm->credentials();
            $this->fcm->accessToken($credentials);
        } catch (Throwable $e) {
            Log::warning('FCM not configured correctly: '.$e->getMessage());

            return;
        }

        $message = $notification->toPush($notifiable);
        foreach ($tokens as $token) {
            try {
                $response = $this->fcm->send($token, $message, $credentials);
            } catch (Throwable $e) {
                Log::warning('FCM send failed: '.$e->getMessage());

                continue;
            }
            // توکن منقضی یا حذف‌شده: از پایگاه داده پاک شود
            if (in_array($response->status(), [404, 410], true) || str_contains($response->body(), 'UNREGISTERED')) {
                Device::where('token', $token)->delete();
            }
        }
    }
}
