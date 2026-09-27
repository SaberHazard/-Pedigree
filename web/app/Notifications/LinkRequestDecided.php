<?php

namespace App\Notifications;

use App\Models\LinkRequest;

class LinkRequestDecided extends AppNotification
{
    public function __construct(public LinkRequest $request) {}

    public function toArray(object $notifiable): array
    {
        $accepted = $this->request->status === 'accepted';

        return [
            'kind' => 'link_decided',
            'title' => $accepted ? 'درخواست اتصال پذیرفته شد' : 'درخواست اتصال رد شد',
            'body' => 'درخواست اتصال «'.$this->request->subject?->fullName().'» و «'.$this->request->target?->fullName().'» '.($accepted ? 'پذیرفته شد.' : 'رد شد.'),
            'link' => '#/person/'.$this->request->subject_id,
            'link_request_id' => $this->request->id,
        ];
    }
}
