<?php

namespace App\Notifications;

use App\Models\GroupMessage;
use App\Models\User;

/**
 * کسی در «گروه خاندان» به پیام شما پاسخ داد
 */
class GroupReply extends AppNotification
{
    public function __construct(public User $from, public GroupMessage $message) {}

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'group_reply',
            'title' => $this->from->displayName().' به پیام شما در گروه خاندان پاسخ داد',
            'body' => $this->message->kind === GroupMessage::KIND_MEDIA ? '📷 '.mb_strimwidth($this->message->body, 0, 100, '…') : mb_strimwidth($this->message->body, 0, 120, '…'),
            'link' => '#/group?m='.$this->message->id,
        ];
    }
}
