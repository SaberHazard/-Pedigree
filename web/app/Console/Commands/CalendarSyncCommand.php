<?php

namespace App\Console\Commands;

use App\Services\Calendar\OfficialCalendarSync;
use App\Support\ErrorReporter;
use App\Support\Jalali;
use Illuminate\Console\Command;
use Throwable;

class CalendarSyncCommand extends Command
{
    protected $signature = 'pedigree:calendar-sync {--limit=40 : بیشترین تعداد روز در هر اجرا} {--year=* : سال خورشیدی (پیش‌فرض امسال و سال بعد)}';

    protected $description = 'همگام‌سازی تقویم رسمی (تعطیلی‌ها، مناسبت‌ها و آغاز رسمی ماه‌های قمری)';

    public function handle(OfficialCalendarSync $sync): int
    {
        [$y] = Jalali::today();
        $years = array_values(array_filter(array_map('intval', (array) $this->option('year')), fn (int $year) => $year >= 1300 && $year <= 1600)) ?: [$y, $y + 1];
        try {
            $stats = $sync->sync($years, max(1, min(2000, (int) $this->option('limit'))));
            $this->info("official calendar: fetched {$stats['fetched']}, failed {$stats['failed']}, hijri months {$stats['months']}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $ref = ErrorReporter::record($e);
            $this->error('calendar sync failed'.($ref ? " (ref {$ref})" : ''));

            return self::FAILURE;
        }
    }
}
