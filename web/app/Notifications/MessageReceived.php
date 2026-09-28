<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\User;
use App\Notifications\Channels\PushChannel;

/**
 * پیام جدید در پیام‌رسان — فقط روی گوشی (پوش)، نه در فهرست اعلان‌ها (شمارنده پیام‌ها جدا است).
 * متن پیام در پوش نمی‌آید تا از سرورهای Google عبور نکند.
 */
class MessageReceived extends AppNotification
{
    public function __construct(public User $from, public Conversation $conversation) {}

    public function via(object $notifiable): array
    {
        return config('services.fcm.enabled') ? [PushChannel::class] : [];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'message',
            'title' => 'پیام جدید از '.$this->from->displayName(),
            'body' => 'برای خواندن، پیام‌رسان را باز کنید.',
            'link' => '#/messages/'.$this->conversation->id,
        ];
    }
}
