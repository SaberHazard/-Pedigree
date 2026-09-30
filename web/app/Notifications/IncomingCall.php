<?php

namespace App\Notifications;

use App\Models\Call;
use App\Models\User;
use App\Notifications\Channels\PushChannel;

/**
 * «📞 تماس از ...» — فقط پوش پرصدا روی گوشی (اگر Firebase تنظیم شده) تا اپ باز شود و زنگ بخورد؛
 * در فهرست اعلان‌ها ذخیره نمی‌شود (تماس بی‌پاسخ جداگانه با «تماس از دست رفته» ثبت می‌شود).
 */
class IncomingCall extends AppNotification
{
    public function __construct(public Call $call, public User $caller) {}

    public function via(object $notifiable): array
    {
        return config('services.fcm.enabled') ? [PushChannel::class] : [];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'call',
            'title' => ($this->call->kind === 'video' ? '🎥 تماس تصویری از ' : '📞 تماس از ').$this->caller->displayName(),
            'body' => 'برای پاسخ دادن بزنید',
            'link' => '#/calls?answer='.$this->call->id,
        ];
    }

    public function toPush(object $notifiable): array
    {
        return parent::toPush($notifiable) + ['alarm' => true];
    }
}
