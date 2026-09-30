<?php

namespace App\Notifications;

/** «📅 یادآوری مناسبت‌ها» — خلاصه روزانه تولدها، سالگردها و تعطیلات */
class OccasionDigest extends AppNotification
{
    use SkipsPushWhenLocalAlarms;

    public function __construct(public string $title, public string $text, public string $link, public ?int $settingSavedAt = null) {}

    protected function savedAt(): ?int
    {
        return $this->settingSavedAt;
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'occasion_digest', 'title' => $this->title, 'body' => $this->text, 'link' => $this->link];
    }
}
