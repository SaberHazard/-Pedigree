<?php

namespace App\Notifications;

use App\Models\Call;
use App\Models\User;

/** «تماس از دست رفته» */
class MissedCall extends AppNotification
{
    public function __construct(public Call $call, public User $caller) {}

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'missed_call',
            'title' => '📵 تماس از دست رفته',
            'body' => ($this->call->kind === 'video' ? 'تماس تصویری از ' : 'تماس از ').$this->caller->displayName(),
            'link' => '#/calls',
            'call_id' => $this->call->id,
        ];
    }
}
