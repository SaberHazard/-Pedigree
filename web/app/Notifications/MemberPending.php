<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;

/**
 * برای مدیران: عضو تازه‌ای ثبت‌نام کرده و منتظر تأیید عضویت است
 */
class MemberPending extends AppNotification
{
    use Queueable;

    public function __construct(public User $member) {}

    public function toArray(object $notifiable): array
    {
        $name = $this->member->person ? trim($this->member->person->first_name.' '.$this->member->person->last_name) : 'عضو تازه';

        return [
            'kind' => 'member_pending',
            'title' => '🙋 درخواست عضویت: '.$name,
            'body' => mb_substr((string) $this->member->join_note, 0, 150) ?: 'برای بررسی به پنل مدیریت بروید.',
            'link' => '#/admin/users?status=pending',
        ];
    }
}
