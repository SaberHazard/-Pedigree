<?php

namespace App\Notifications;

use App\Models\EditRequest;
use Illuminate\Bus\Queueable;

/**
 * برای پیشنهاددهنده: پیشنهاد ویرایش شما تأیید یا رد شد
 */
class EditRequestDecided extends AppNotification
{
    use Queueable;

    public function __construct(public EditRequest $request) {}

    public function toArray(object $notifiable): array
    {
        $p = $this->request->person;
        $name = $p ? trim($p->first_name.' '.$p->last_name) : '';
        $ok = $this->request->status === EditRequest::STATUS_APPROVED;

        return [
            'kind' => 'edit_request_decided',
            'title' => $ok ? '✅ پیشنهاد ویرایش شما تأیید شد' : 'پیشنهاد ویرایش شما پذیرفته نشد',
            'body' => ($name ? 'پروفایل '.$name : 'پیشنهاد شما').($ok ? ' به‌روز شد.' : ($this->request->decision_note ? ': '.$this->request->decision_note : '.')),
            'link' => $p ? '#/person/'.$p->id : '#/',
        ];
    }
}
