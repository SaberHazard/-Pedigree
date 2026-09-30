<?php

namespace App\Services\Reminders;

use App\Models\Reminder;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Notifications\OccasionDigest;
use App\Notifications\ReminderDue;
use App\Services\Calendar\CalendarService;
use App\Support\ErrorReporter;
use App\Support\Hijri;
use App\Support\Jalali;
use App\Support\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * هشدارها و یادآورها (همه به وقت تهران):
 *  - هشدار شخصی: هر تاریخ و ساعت، با توضیح و تکرار؛ «زودتر یادآوری کن»
 *  - یادآوری مناسبت‌ها: هر روز سر ساعت انتخابی، خلاصه تولدها، سالگردها و تعطیلات امروز/فردا/هفته بعد
 *
 * سه راه زنگ خوردن (برای دقت و اطمینان):
 *  ۱) روی گوشی (اپ اندروید/iOS): هشدار محلی خود گوشی سر ثانیه، حتی بدون اینترنت (فهرست از upcoming می‌رود)
 *  ۲) در صفحه باز سایت: زنگ و پیام سر ثانیه با ساعت همگام‌شده با سرور
 *  ۳) اعلان سایت (و پوش Firebase اگر تنظیم شده) با زمان‌بند دقیقه‌ای سرور
 */
class ReminderService
{
    /** @var array<string, array> ماه‌های ساخته‌شده در همین درخواست */
    private array $months = [];

    public function __construct(
        private readonly ReminderSchedule $schedule,
        private readonly CalendarService $calendar,
    ) {}

    /** زمان زنگ بعدی را از اکنون حساب و ذخیره می‌کند */
    public function refresh(Reminder $r): Reminder
    {
        $next = $r->active ? $this->schedule->nextFire($r, CarbonImmutable::now()) : null;
        $r->next_at = $next;
        if ($next === null && $r->repeat === 'none' && $r->last_sent_at !== null) {
            $r->active = false;
        }
        $r->save();

        return $r;
    }

    /**
     * رخدادهای هشدارهای کاربر در یک بازه، به تفکیک روز خورشیدی (برای تقویم)
     *
     * @return array<string, array<int, array>>
     */
    public function byJalaliDay(?User $user, string $fromUtc, string $toUtc): array
    {
        if ($user === null) {
            return [];
        }
        $from = CarbonImmutable::parse($fromUtc, 'UTC');
        $to = CarbonImmutable::parse($toUtc, 'UTC');
        $out = [];
        foreach (Reminder::query()->where('user_id', $user->id)->where('active', true)->limit(Reminder::MAX_PER_USER)->get() as $r) {
            foreach ($this->schedule->between($r, $from, $to, 62) as $at) {
                $key = sprintf('%04d-%02d-%02d', ...Jalali::fromGregorian((int) $at->format('Y'), (int) $at->format('n'), (int) $at->format('j')));
                $out[$key][] = $this->present($r, $at);
            }
        }
        foreach ($out as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['time'], $b['time']));
        }

        return $out;
    }

    /**
     * زنگ‌های پیش رو (برای هشدار محلی گوشی و زنگ صفحه باز): هشدارهای شخصی و یادآوری روزانه مناسبت‌ها
     *
     * @return array<int, array{key: string, id: ?int, title: string, body: string, at: int, link: string}>
     */
    public function upcoming(User $user, int $days = 30, int $max = 60): array
    {
        $now = CarbonImmutable::now();
        $to = $now->addDays($days);
        $items = [];
        foreach (Reminder::query()->where('user_id', $user->id)->where('active', true)->limit(Reminder::MAX_PER_USER)->get() as $r) {
            $before = (int) $r->remind_before;
            foreach ($this->schedule->between($r, $now->addMinutes($before)->subSecond(), $to->addMinutes($before), 40) as $at) {
                $fire = $at->subMinutes($before);
                if ($fire->lessThanOrEqualTo($now)) {
                    continue;
                }
                $items[] = [
                    'key' => 'r'.$r->id.'-'.$fire->getTimestamp(),
                    'id' => $r->id,
                    'title' => '⏰ '.$r->title,
                    'body' => $this->body($r, $at),
                    'at' => $fire->getTimestamp() * 1000,
                    'link' => '#/calendar?date='.$this->jalaliKey($at),
                ];
            }
        }

        $setting = ReminderSetting::query()->find($user->id);
        if ($setting && $setting->enabled) {
            for ($i = 0; $i <= $days; $i++) {
                $day = $now->setTimezone(ReminderSchedule::TZ)->addDays($i);
                [$h, $m] = array_map('intval', explode(':', $setting->time));
                $fire = CarbonImmutable::create((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'), $h, $m, 0, ReminderSchedule::TZ);
                if ($fire->lessThanOrEqualTo($now)) {
                    continue;
                }
                $digest = $this->digest($user, $setting, $fire);
                if ($digest !== null) {
                    $items[] = [
                        'key' => 'o-'.$fire->getTimestamp(),
                        'id' => null,
                        'title' => $digest['title'],
                        'body' => $digest['body'],
                        'at' => $fire->getTimestamp() * 1000,
                        'link' => $digest['link'],
                    ];
                }
            }
        }

        usort($items, fn ($a, $b) => $a['at'] <=> $b['at']);

        return array_slice($items, 0, $max);
    }

    /**
     * زمان‌بند دقیقه‌ای: هشدارهای سررسیده و یادآوری مناسبت‌های این دقیقه
     *
     * @return array{sent: int, digests: int, failed: int}
     */
    public function dispatchDue(): array
    {
        $stats = ['sent' => 0, 'digests' => 0, 'failed' => 0];
        $now = CarbonImmutable::now();

        Reminder::query()->where('active', true)->whereNotNull('next_at')->where('next_at', '<=', $now)
            ->orderBy('next_at')->limit(1000)->get()
            ->each(function (Reminder $r) use ($now, &$stats) {
                try {
                    $due = CarbonImmutable::instance($r->next_at);
                    $next = $this->schedule->nextFire($r, $now);
                    // «ادعا» با شرط همان next_at: اگر زمان‌بند دوبار هم اجرا شود، زنگ تکراری نمی‌رود
                    // (toBase: زمان «آخرین ویرایش» دست نمی‌خورد؛ اپ گوشی با همان می‌فهمد این هشدار را دارد یا نه)
                    $claimed = Reminder::query()->whereKey($r->id)->where('next_at', $r->next_at)->toBase()->update([
                        'next_at' => $next,
                        'last_sent_at' => $now,
                        'active' => $next !== null || $r->repeat !== 'none',
                    ]);
                    if ($claimed === 0) {
                        return;
                    }
                    // اگر سرور مدتی خاموش بوده، زنگ خیلی دیرشده فرستاده نمی‌شود (فقط یک بار و تا ۶ ساعت)
                    $late = $due->diffInMinutes($now, true);
                    if ($late > 360) {
                        return;
                    }
                    $user = $r->user;
                    if ($user && $user->status === User::STATUS_ACTIVE) {
                        $at = $due->addMinutes((int) $r->remind_before)->setTimezone(ReminderSchedule::TZ);
                        $user->notify(new ReminderDue($r, $this->body($r, $at), $this->jalaliKey($at), $late > 5));
                        $stats['sent']++;
                    }
                } catch (Throwable $e) {
                    $stats['failed']++;
                    ErrorReporter::record($e);
                }
            });

        // دقیقه جاری و ده دقیقه قبل از آن (همان روز): اگر اجرای زمان‌بند یک دقیقه جا بیفتد، یادآوری از دست نمی‌رود؛
        // قفل روزانه هر کاربر جلوی ارسال دوباره را می‌گیرد
        $local = $now->setTimezone(ReminderSchedule::TZ);
        $minutes = [];
        for ($i = 0; $i <= 10; $i++) {
            $t = $local->subMinutes($i);
            if ($t->format('Ymd') === $local->format('Ymd')) {
                $minutes[] = $t->format('H:i');
            }
        }
        ReminderSetting::query()->where('enabled', true)->whereIn('time', $minutes)->with('user')->limit(5000)->get()
            ->each(function (ReminderSetting $s) use ($now, &$stats) {
                try {
                    $user = $s->user;
                    if (! $user || $user->status !== User::STATUS_ACTIVE) {
                        return;
                    }
                    $day = $now->setTimezone(ReminderSchedule::TZ)->format('Ymd');
                    if (! Cache::add("occasion-digest:{$user->id}:{$day}", 1, 86400 * 2)) {
                        return;
                    }
                    $digest = $this->digest($user, $s, $now);
                    if ($digest !== null) {
                        $user->notify(new OccasionDigest($digest['title'], $digest['body'], $digest['link'], $s->updated_at?->getTimestamp()));
                        $stats['digests']++;
                    }
                } catch (Throwable $e) {
                    $stats['failed']++;
                    ErrorReporter::record($e);
                }
            });

        return $stats;
    }

    /**
     * خلاصه مناسبت‌های «امروز، فردا، ... روز دیگر» برای یک کاربر
     *
     * @return array{title: string, body: string, link: string}|null
     */
    public function digest(User $user, ReminderSetting $setting, CarbonImmutable $at): ?array
    {
        $categories = array_intersect((array) $setting->categories, array_keys(ReminderSetting::CATEGORIES));
        $offsets = array_values(array_intersect(array_map('intval', (array) $setting->days_before), ReminderSetting::DAYS_BEFORE));
        if (! $categories || ! $offsets) {
            return null;
        }
        sort($offsets);
        $lines = [];
        $firstKey = null;
        foreach ($offsets as $offset) {
            $day = $at->setTimezone(ReminderSchedule::TZ)->addDays($offset);
            [$jy, $jm, $jd] = Jalali::fromGregorian((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));
            $monthKey = $user->id.'-'.$setting->degree.'-'.$jy.'-'.$jm;
            $this->months[$monthKey] ??= $this->calendar->month($user, $jy, $jm, null, (int) $setting->degree);
            $info = $this->months[$monthKey]['days'][$jd - 1] ?? null;
            if ($info === null) {
                continue;
            }
            $parts = [];
            foreach ($info['family'] as $f) {
                $type = match ($f['type']) {
                    'birthday' => in_array('birthday', $categories, true) ? '🎂 تولد '.$f['person']['name'].($f['years'] > 0 ? ' ('.$f['years'].' سالگی)' : '') : null,
                    'anniversary' => in_array('anniversary', $categories, true) ? '💍 سالگرد ازدواج '.$f['person']['name'].($f['years'] > 0 ? ' ('.$f['years'].' سال)' : '') : null,
                    'death_anniversary' => in_array('death', $categories, true) ? '🕯 سالگرد درگذشت '.$f['person']['name'] : null,
                    default => null,
                };
                if ($type) {
                    $parts[] = $type;
                }
            }
            if (in_array('official', $categories, true)) {
                foreach ($info['events'] as $e) {
                    // سالروزهای تاریخی (یادداشت با سال، مثل «۱۳ دی 1338») در خلاصه روزانه نمی‌آیند
                    $historic = preg_match('/[0-9۰-۹]{4}/u', (string) ($e['note'] ?? '')) === 1;
                    if ($e['holiday'] || ($e['kind'] !== 'international' && ! $historic)) {
                        $parts[] = ($e['holiday'] ? '🔴 ' : '📅 ').$e['title'].($e['holiday'] ? ' (تعطیل)' : '');
                    }
                }
            }
            if (! $parts) {
                continue;
            }
            $when = match ($offset) {
                0 => 'امروز',
                1 => 'فردا',
                7 => 'یک هفته دیگر',
                14 => 'دو هفته دیگر',
                default => $offset.' روز دیگر',
            };
            $lines[] = $when.' ('.$jd.' '.Jalali::MONTHS[$jm].'): '.implode('، ', array_slice($parts, 0, 6));
            $firstKey ??= sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
        }
        if (! $lines) {
            return null;
        }

        return [
            'title' => '📅 یادآوری مناسبت‌ها',
            'body' => PersianText::toPersianDigits(mb_substr(implode("\n", $lines), 0, 900)),
            'link' => '#/calendar?date='.$firstKey,
        ];
    }

    public function present(Reminder $r, ?CarbonImmutable $occurrence = null): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'note' => $r->note,
            'time' => $r->time,
            'starts_on' => $r->starts_on,
            'repeat' => $r->repeat,
            'repeat_label' => Reminder::REPEATS[$r->repeat] ?? '',
            'until_on' => $r->until_on,
            'remind_before' => $r->remind_before,
            'active' => $r->active,
            'person_id' => $r->person_id,
            'next_at' => $r->next_at?->toIso8601String(),
            'occurs_at' => $occurrence?->toIso8601String(),
        ];
    }

    private function body(Reminder $r, CarbonImmutable $at): string
    {
        $at = $at->setTimezone(ReminderSchedule::TZ);
        [$jy, $jm, $jd] = Jalali::fromGregorian((int) $at->format('Y'), (int) $at->format('n'), (int) $at->format('j'));
        $when = $jd.' '.Jalali::MONTHS[$jm].' ساعت '.$at->format('H:i');
        $note = $r->note ? "\n".mb_substr($r->note, 0, 300) : '';

        return PersianText::toPersianDigits(($r->remind_before > 0 ? 'موعد: ' : '').$when.$note);
    }

    private function jalaliKey(CarbonImmutable $at): string
    {
        $at = $at->setTimezone(ReminderSchedule::TZ);

        return sprintf('%04d-%02d-%02d', ...Jalali::fromGregorian((int) $at->format('Y'), (int) $at->format('n'), (int) $at->format('j')));
    }

    /** برای آزمون: روز ژولیانی امروز تهران */
    public static function todayJdn(): int
    {
        $t = CarbonImmutable::now(ReminderSchedule::TZ);

        return Hijri::gregorianToJdn((int) $t->format('Y'), (int) $t->format('n'), (int) $t->format('j'));
    }
}
