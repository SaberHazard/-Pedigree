<?php

namespace App\Notifications;

use App\Models\SupportThread;
use App\Models\User;
use Illuminate\Bus\Queueable;

/** برای مدیران: پیام تازه در گفتگو با پشتیبانی */
class SupportRequested extends AppNotification
{
    use Queueable;

    public function __construct(public SupportThread $thread, public User $from) {}

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'support',
            'title' => '💬 پیام پشتیبانی از '.$this->from->displayName(),
            'body' => 'یک عضو با پشتیبانی سایت گفتگو دارد.',
            'link' => '#/admin/support?thread='.$this->thread->id,
        ];
    }
}
