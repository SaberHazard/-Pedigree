<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;

/**
 * برای عضو تازه: عضویت شما تأیید شد
 */
class MemberApproved extends AppNotification
{
    use Queueable;

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'member_approved',
            'title' => '🎉 عضویت شما تأیید شد',
            'body' => 'به '.config('pedigree.site_name').' خوش آمدید! حالا می‌توانید شجره‌نامه را ببینید و پروفایل خود را کامل کنید.',
            'link' => '#/',
        ];
    }
}
