<?php

namespace App\Notifications;

use App\Models\SupportThread;
use Illuminate\Bus\Queueable;

/** برای عضو: پشتیبانی پاسخ داد */
class SupportReplied extends AppNotification
{
    use Queueable;

    public function __construct(public SupportThread $thread) {}

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'support_reply',
            'title' => '💬 پاسخ پشتیبانی',
            'body' => 'پشتیبانی '.config('pedigree.site_name').' به پیام شما پاسخ داد.',
            'link' => '#/support',
        ];
    }
}
