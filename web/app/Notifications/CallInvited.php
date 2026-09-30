<?php

namespace App\Notifications;

use App\Models\CallInvite;
use App\Models\User;
use App\Services\Calls\CallLinks;

/** «دعوت به تماس» با لینک سرویس بیرونی (تماس گروهی واتس‌اپ، گوگل‌میت ...) */
class CallInvited extends AppNotification
{
    public function __construct(public CallInvite $invite, public User $from) {}

    public function toArray(object $notifiable): array
    {
        $label = CallLinks::PROVIDERS[$this->invite->provider]['label'] ?? 'تماس';

        return [
            'kind' => 'call_invite',
            'title' => '📞 دعوت به '.$label,
            'body' => $this->from->displayName().': '.$this->invite->title,
            'link' => '#/calls',
            'invite_id' => $this->invite->id,
        ];
    }
}
