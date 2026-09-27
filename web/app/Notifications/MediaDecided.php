<?php

namespace App\Notifications;

use App\Models\Media;

class MediaDecided extends AppNotification
{
    public function __construct(public Media $media) {}

    public function toArray(object $notifiable): array
    {
        $approved = $this->media->isApproved();
        $person = $this->media->person;
        $what = $this->media->isVideo() ? 'ویدیوی' : 'عکسِ';

        return [
            'kind' => 'media_decided',
            'title' => $approved ? 'فایل شما تأیید شد' : 'فایل شما تأیید نشد',
            'body' => $approved
                ? "{$what} آپلودشده برای «{$person?->fullName()}» تأیید و منتشر شد."
                : "{$what} آپلودشده برای «{$person?->fullName()}» توسط بستگان تأیید نشد.",
            'link' => '#/person/'.$this->media->person_id,
            'media_id' => $this->media->id,
            'person_id' => $this->media->person_id,
            'approved' => $approved,
        ];
    }
}
