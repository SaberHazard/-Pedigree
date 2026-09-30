<?php

namespace App\Notifications;

use App\Models\Reminder;

/** «⏰ موعد هشدار» — در سایت و (اگر گوشی هشدار محلی ندارد) با پوش پرصدا */
class ReminderDue extends AppNotification
{
    use SkipsPushWhenLocalAlarms;

    public function __construct(public Reminder $reminder, public string $text, public string $date, public bool $late = false) {}

    protected function savedAt(): ?int
    {
        return $this->reminder->updated_at?->getTimestamp();
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'reminder',
            'title' => '⏰ '.$this->reminder->title.($this->late ? ' (با تأخیر)' : ''),
            'body' => $this->text,
            'link' => '#/calendar?date='.$this->date,
            'reminder_id' => $this->reminder->id,
        ];
    }
}
