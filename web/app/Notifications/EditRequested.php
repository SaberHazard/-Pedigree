<?php

namespace App\Notifications;

use App\Models\EditRequest;
use Illuminate\Bus\Queueable;

/**
 * برای مدیران: یکی از بستگان درجه دو یا سه ویرایشی پیشنهاد داده است
 */
class EditRequested extends AppNotification
{
    use Queueable;

    public function __construct(public EditRequest $request) {}

    public function toArray(object $notifiable): array
    {
        $p = $this->request->person;
        $who = $this->request->requester?->displayName() ?? 'یکی از اعضا';
        $what = $this->request->kind === EditRequest::KIND_MARRIAGE ? 'ازدواج' : 'پروفایل';

        return [
            'kind' => 'edit_request',
            'title' => '✏️ پیشنهاد ویرایش '.$what.($p ? ': '.trim($p->first_name.' '.$p->last_name) : ''),
            'body' => $who.' تغییری پیشنهاد داده که تا تأیید شما نمایش داده نمی‌شود.',
            'link' => '#/admin/edits',
        ];
    }
}
