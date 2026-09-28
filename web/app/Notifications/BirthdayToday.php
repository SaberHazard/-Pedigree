<?php

namespace App\Notifications;

use App\Models\Person;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** «امروز تولد فلانی است» — برای همه اعضا جز خود شخص (در سایت، اپ و پوش) */
class BirthdayToday extends AppNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Person $person, public ?int $age = null) {}

    public function toArray(object $notifiable): array
    {
        $name = $this->person->fullName();
        $age = $this->age ? ' ('.$this->persianNumber($this->age).' سالگی)' : '';

        return [
            'kind' => 'birthday',
            'title' => "🎂 امروز تولد {$name} است",
            'body' => "تولد {$name}{$age} را تبریک بگویید.",
            'link' => '#/greetings?person='.$this->person->id,
            'person_id' => $this->person->id,
        ];
    }

    private function persianNumber(int $n): string
    {
        return strtr((string) $n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
}
