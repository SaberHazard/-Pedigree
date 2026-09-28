<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * اعلان همگانی مدیر کل به همه اعضا
 */
class Broadcast extends AppNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $title, public string $body) {}

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'broadcast',
            'title' => '📢 '.$this->title,
            'body' => $this->body,
            'link' => '#/',
        ];
    }
}
