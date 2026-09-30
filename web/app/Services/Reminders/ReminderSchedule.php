<?php

namespace App\Services\Reminders;

use App\Models\Reminder;
use App\Services\Calendar\HijriCalendar;
use App\Support\Hijri;
use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * محاسبه زمان دقیق رخدادهای یک هشدار (وقت تهران، مستقل از منطقه زمانی سرور):
 * یک بار، روزانه، هفتگی، ماهانه/سالانه خورشیدی (روز ۳۱ در ماه‌های ۳۰ روزه و ۳۰ اسفند در سال غیرکبیسه به آخرین روز ماه
 * می‌رود) و ماهانه/سالانه قمری مطابق تقویم رسمی.
 */
class ReminderSchedule
{
    public const TZ = 'Asia/Tehran';

    public function __construct(private readonly HijriCalendar $hijri) {}

    /** نخستین زمان زنگ (UTC) بعد از $after؛ null = دیگر رخدادی ندارد */
    public function nextFire(Reminder $r, CarbonImmutable $after): ?CarbonImmutable
    {
        $before = max(0, (int) $r->remind_before);
        $target = $after->addMinutes($before)->setTimezone(self::TZ);
        // از یک روز قبل از روزِ «بعد + زودتر» شروع می‌کنیم
        $fromJdn = Hijri::gregorianToJdn((int) $target->format('Y'), (int) $target->format('n'), (int) $target->format('j')) - 1;
        foreach ($this->occurrences($r, $fromJdn, 800) as $jdn) {
            $fire = $this->instant($jdn, $r->time)->subMinutes($before);
            if ($fire->greaterThan($after)) {
                return $fire->utc();
            }
        }

        return null;
    }

    /**
     * رخدادهای بین دو لحظه (برای نمایش در تقویم و فهرست زنگ‌های آینده)
     *
     * @return array<int, CarbonImmutable> زمان رخداد (نه زنگ) به وقت تهران
     */
    public function between(Reminder $r, CarbonImmutable $from, CarbonImmutable $to, int $max = 400): array
    {
        $start = $from->setTimezone(self::TZ);
        $fromJdn = Hijri::gregorianToJdn((int) $start->format('Y'), (int) $start->format('n'), (int) $start->format('j')) - 1;
        $out = [];
        foreach ($this->occurrences($r, $fromJdn, $max + 2) as $jdn) {
            $at = $this->instant($jdn, $r->time);
            if ($at->greaterThan($to)) {
                break;
            }
            if ($at->greaterThanOrEqualTo($from)) {
                $out[] = $at;
                if (count($out) >= $max) {
                    break;
                }
            }
        }

        return $out;
    }

    /** لحظه یک روز (JDN) و ساعت HH:MM به وقت تهران */
    public function instant(int $jdn, string $time): CarbonImmutable
    {
        [$gy, $gm, $gd] = Hijri::jdnToGregorian($jdn);
        [$h, $i] = array_map('intval', explode(':', $time.':0'));

        return CarbonImmutable::create($gy, $gm, $gd, $h, $i, 0, self::TZ);
    }

    /**
     * روزهای رخداد (JDN) به ترتیب، از $fromJdn به بعد
     *
     * @return \Generator<int>
     */
    private function occurrences(Reminder $r, int $fromJdn, int $limit): \Generator
    {
        [$sy, $sm, $sd] = array_map('intval', explode('-', $r->starts_on));
        $startJdn = Hijri::gregorianToJdn(...Jalali::toGregorian($sy, $sm, $sd));
        $untilJdn = null;
        if ($r->until_on && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $r->until_on, $u)) {
            $untilJdn = Hijri::gregorianToJdn(...Jalali::toGregorian((int) $u[1], (int) $u[2], (int) $u[3]));
        }
        $from = max($fromJdn, $startJdn);
        $count = 0;
        $emit = function (int $jdn) use ($startJdn, $untilJdn, $from): ?int {
            if ($jdn < $startJdn || $jdn < $from) {
                return null;
            }

            return $untilJdn !== null && $jdn > $untilJdn ? -1 : $jdn;
        };

        switch ($r->repeat) {
            case 'daily':
            case 'weekly':
                $step = $r->repeat === 'daily' ? 1 : 7;
                $jdn = $startJdn + (int) (ceil(($from - $startJdn) / $step) * $step);
                for (; $count < $limit; $jdn += $step, $count++) {
                    $v = $emit($jdn);
                    if ($v === -1) {
                        return;
                    }
                    if ($v !== null) {
                        yield $v;
                    }
                }

                return;

            case 'monthly':
            case 'yearly':
                [$fy, $fm] = Jalali::fromGregorian(...Hijri::jdnToGregorian($from));
                $index = $r->repeat === 'monthly'
                    ? max(0, ($fy * 12 + $fm) - ($sy * 12 + $sm) - 1)
                    : max(0, $fy - $sy - 1);
                for (; $count < $limit; $index++, $count++) {
                    if ($r->repeat === 'monthly') {
                        $abs = $sy * 12 + ($sm - 1) + $index;
                        $y = intdiv($abs, 12);
                        $m = $abs % 12 + 1;
                    } else {
                        [$y, $m] = [$sy + $index, $sm];
                    }
                    if ($y > 3177) {
                        return;
                    }
                    $d = min($sd, Jalali::monthLength($y, $m));
                    $v = $emit(Hijri::gregorianToJdn(...Jalali::toGregorian($y, $m, $d)));
                    if ($v === -1) {
                        return;
                    }
                    if ($v !== null) {
                        yield $v;
                    }
                }

                return;

            case 'monthly_hijri':
            case 'yearly_hijri':
                [$hy, $hm, $hd] = $this->hijri->fromGregorian(...Hijri::jdnToGregorian($startJdn));
                [$fy, $fm] = $this->hijri->fromGregorian(...Hijri::jdnToGregorian($from));
                $index = $r->repeat === 'monthly_hijri'
                    ? max(0, ($fy * 12 + $fm) - ($hy * 12 + $hm) - 1)
                    : max(0, $fy - $hy - 1);
                for (; $count < $limit; $index++, $count++) {
                    if ($r->repeat === 'monthly_hijri') {
                        $abs = $hy * 12 + ($hm - 1) + $index;
                        $y = intdiv($abs, 12);
                        $m = $abs % 12 + 1;
                    } else {
                        [$y, $m] = [$hy + $index, $hm];
                    }
                    $d = min($hd, $this->hijri->monthLength($y, $m));
                    $v = $emit(Hijri::gregorianToJdn(...$this->hijri->toGregorian($y, $m, $d)));
                    if ($v === -1) {
                        return;
                    }
                    if ($v !== null) {
                        yield $v;
                    }
                }

                return;

            default:
                $v = $emit($startJdn);
                if ($v !== null && $v !== -1) {
                    yield $v;
                }
        }
    }
}
