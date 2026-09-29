<?php

namespace App\Services\Occasions;

use App\Models\Person;
use App\Models\User;
use App\Notifications\BirthdayToday;
use App\Support\ErrorReporter;
use App\Support\Jalali;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * تولدهای امروز (تقویم شمسی، به وقت تهران) و اعلان آن به اعضا.
 *
 * - فقط زندگان با تاریخ تولد کامل (سال-ماه-روز)
 * - متولدین ۳۰ اسفند در سال‌های غیرکبیسه، ۲۹ اسفند تبریک گفته می‌شوند
 * - اعلان به همه اعضای فعال، جز خود شخص (قابل خاموش کردن نیست)
 * - هر تولد در هر سال فقط یک بار اعلان می‌شود (occasion_runs)
 */
class BirthdayService
{
    /** @return array{0:int,1:int,2:int} امروز شمسی */
    public function today(): array
    {
        return Jalali::today(now()->setTimezone('Asia/Tehran'));
    }

    /**
     * کسانی که تولدشان در بازه [امروز - before, امروز + after] است
     *
     * @return Collection<int, array{person: Person, in_days: int, age: ?int}>
     */
    public function around(int $before = 0, int $after = 0): Collection
    {
        [$jy, $jm, $jd] = $this->today();
        $base = new \DateTimeImmutable(vsprintf('%04d-%02d-%02d', Jalali::toGregorian($jy, $jm, $jd)), new \DateTimeZone('Asia/Tehran'));
        $days = [];
        for ($offset = -$before; $offset <= $after; $offset++) {
            $date = $base->modify(($offset >= 0 ? '+' : '').$offset.' day');
            [$y, $m, $d] = Jalali::fromGregorian((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
            $days[sprintf('%02d-%02d', $m, $d)] = [$offset, $y];
            // ۳۰ اسفند در سال غیرکبیسه وجود ندارد
            if ($m === 12 && $d === 29 && ! Jalali::isLeap($y)) {
                $days['12-30'] = [$offset, $y];
            }
        }

        $out = collect();
        $query = Person::query()->with('avatar')->where('is_deceased', false)
            ->where(function ($q) use ($days) {
                foreach (array_keys($days) as $md) {
                    $q->orWhere('birth_date', 'like', '____-'.$md);
                }
            });
        foreach ($query->get() as $person) {
            $md = substr((string) $person->birth_date, 5);
            if (! isset($days[$md])) {
                continue;
            }
            [$offset, $year] = $days[$md];
            $birthYear = (int) substr((string) $person->birth_date, 0, 4);
            $out->push([
                'person' => $person,
                'in_days' => $offset,
                'age' => $birthYear > 0 && $birthYear <= $year ? $year - $birthYear : null,
            ]);
        }

        return $out->sortBy('in_days')->values();
    }

    /** @return Collection<int, array{person: Person, in_days: int, age: ?int}> تولدهای امروز */
    public function todays(): Collection
    {
        return $this->around(0, 0);
    }

    /** یک بار اجرا برای یک کلید (مثلاً اعلان تولد فلانی در ۱۴۰۵)؛ false اگر قبلاً اجرا شده */
    public static function claim(string $key): bool
    {
        // کلید یکتا؛ insertOrIgnore بدون خطا (در PostgreSQL خطای تکراری تراکنش را خراب می‌کند)
        return DB::table('occasion_runs')->insertOrIgnore(['key' => $key, 'created_at' => now()]) === 1;
    }

    /** اعلان تولدهای امروز؛ تعداد اعلان‌های فرستاده‌شده */
    public function notifyToday(): int
    {
        [$jy] = $this->today();
        $sent = 0;
        foreach ($this->todays() as ['person' => $person, 'age' => $age]) {
            if (! self::claim("birthday-notify:{$person->id}:{$jy}")) {
                continue;
            }
            $notification = new BirthdayToday($person, $age);
            foreach ($this->recipients($person)->chunk(500) as $chunk) {
                // خطای یک دسته (مثلاً سرویس پوش) مانع اعلان بقیه نمی‌شود
                try {
                    Notification::send($chunk, $notification);
                    $sent += $chunk->count();
                } catch (Throwable $e) {
                    ErrorReporter::record($e);
                }
            }
        }

        return $sent;
    }

    /** همه اعضای فعالی که اعلان تولد این شخص را می‌گیرند (جز خودش) */
    public function recipients(Person $person): Collection
    {
        return User::query()->where('status', User::STATUS_ACTIVE)->whereNotNull('last_login_at')
            ->where(fn ($q) => $q->whereNull('person_id')->orWhere('person_id', '!=', $person->id))
            ->get();
    }
}
