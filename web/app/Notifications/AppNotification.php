<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use Illuminate\Notifications\Notification;

/**
 * کلاس پایه اعلان‌ها.
 *
 * هر اعلان در پایگاه داده ذخیره می‌شود (نمایش در سایت/اپ) و اگر پوش
 * (Firebase) تنظیم شده باشد، روی گوشی کاربر هم نمایش داده می‌شود.
 * خروجی toArray یک قالب ثابت دارد: kind, title, body, link
 */
abstract class AppNotification extends Notification
{
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (config('services.fcm.enabled')) {
            $channels[] = PushChannel::class;
        }

        return $channels;
    }

    /** اعلان پوش: عنوان و متن همان اعلان داخلی */
    public function toPush(object $notifiable): array
    {
        $data = $this->toArray($notifiable);

        return [
            'title' => $data['title'] ?? config('pedigree.site_name'),
            'body' => $data['body'] ?? '',
            'data' => ['link' => (string) ($data['link'] ?? '')],
        ];
    }

    abstract public function toArray(object $notifiable): array;
}
