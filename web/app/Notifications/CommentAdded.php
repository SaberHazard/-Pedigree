<?php

namespace App\Notifications;

use App\Models\Person;
use App\Models\PersonComment;
use App\Models\User;

/**
 * یکی از اعضا درباره شخص نظر نوشته است (به خود شخص اطلاع داده می‌شود)
 */
class CommentAdded extends AppNotification
{
    public function __construct(public Person $person, public PersonComment $comment, public User $author) {}

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'comment_added',
            'title' => 'نظر تازه درباره شما',
            'body' => $this->author->displayName().': '.mb_strimwidth($this->comment->body, 0, 120, '…'),
            'link' => '#/person/'.$this->person->id.'/opinions',
            'person_id' => $this->person->id,
        ];
    }
}
