<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;

/**
 * هشدارها روی گوشی با «هشدار محلی» خود اپ سر ثانیه زنگ می‌خورند. اگر اپ این کاربر فهرست زنگ‌ها را بعد از آخرین
 * ویرایش همین هشدار گرفته و این زنگ در بازه همان فهرست است، پوش Firebase فرستاده نمی‌شود تا دو بار زنگ نخورد؛
 * در غیر این صورت (مثلاً هشدار تازه در سایت ساخته شده و اپ هنوز باز نشده) پوش پرصدا و با اولویت بالا می‌رود.
 */
trait SkipsPushWhenLocalAlarms
{
    /** زمان آخرین ذخیره هشدار یا تنظیمی که این اعلان از آن ساخته شده (ثانیه یونیکس) */
    abstract protected function savedAt(): ?int;

    public function via(object $notifiable): array
    {
        $channels = parent::via($notifiable);
        $prefs = (array) ($notifiable->preferences ?? []);
        $fetched = $prefs['local_alarms_at'] ?? null;
        $until = $prefs['local_alarms_until'] ?? null;
        $saved = $this->savedAt();
        if (is_int($fetched) && is_int($until) && $saved !== null && $fetched > $saved && now()->getTimestamp() <= $until) {
            $channels = array_values(array_filter($channels, fn ($c) => $c !== PushChannel::class));
        }

        return $channels;
    }

    public function toPush(object $notifiable): array
    {
        return parent::toPush($notifiable) + ['alarm' => true];
    }
}
