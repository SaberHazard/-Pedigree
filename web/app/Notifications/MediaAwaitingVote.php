<?php

namespace App\Notifications;

use App\Models\Media;

class MediaAwaitingVote extends AppNotification
{
    public function __construct(public Media $media) {}

    public function toArray(object $notifiable): array
    {
        $person = $this->media->person;
        $what = $this->media->isVideo() ? 'ویدیوی' : 'عکسِ';
        $uploader = $this->media->uploader?->displayName() ?? 'یکی از اعضا';

        return [
            'kind' => 'media_vote',
            'title' => 'درخواست تأیید '.($this->media->isVideo() ? 'ویدیو' : 'عکس'),
            'body' => "{$uploader} یک {$what} جدید برای «{$person?->fullName()}» آپلود کرده و منتظر رأی شماست.",
            'link' => '#/approvals',
            'media_id' => $this->media->id,
            'person_id' => $this->media->person_id,
        ];
    }
}
