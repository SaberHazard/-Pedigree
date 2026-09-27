<?php

namespace App\Notifications;

use App\Models\LinkRequest;

class LinkRequested extends AppNotification
{
    public function __construct(public LinkRequest $request) {}

    public function toArray(object $notifiable): array
    {
        $labels = ['spouse' => 'همسرِ', 'father' => 'فرزندِ', 'mother' => 'فرزندِ', 'child' => 'والدِ'];
        $subject = $this->request->subject?->fullName();
        $target = $this->request->target?->fullName();
        $requester = $this->request->requester?->displayName() ?? 'یکی از اعضا';

        return [
            'kind' => 'link_request',
            'title' => 'درخواست اتصال به درخت',
            'body' => "{$requester} درخواست داده «{$subject}» به عنوان ".($labels[$this->request->type] ?? '')."«{$target}» ثبت شود.",
            'link' => '#/approvals',
            'link_request_id' => $this->request->id,
        ];
    }
}
