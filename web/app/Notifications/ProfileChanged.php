<?php

namespace App\Notifications;

use App\Models\Person;
use App\Models\User;

/**
 * وقتی یکی از بستگان پروفایل شخص را ویرایش می‌کند، خود شخص باخبر می‌شود.
 */
class ProfileChanged extends AppNotification
{
    public function __construct(public Person $person, public User $editor, public array $fields) {}

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'profile_changed',
            'title' => 'پروفایل شما ویرایش شد',
            'body' => $this->editor->displayName().' اطلاعات پروفایل شما را تغییر داد.',
            'link' => '#/person/'.$this->person->id.'/history',
            'person_id' => $this->person->id,
            'fields' => $this->fields,
        ];
    }
}
