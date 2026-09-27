<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| زمان‌بندی کارهای دوره‌ای
|--------------------------------------------------------------------------
| روی سرور فقط یک cron لازم است:
|   * * * * * cd /path/to/web && php artisan schedule:run >> /dev/null 2>&1
*/

// جمع‌بندی رأی‌گیری‌هایی که مهلتشان تمام شده
Schedule::command('pedigree:resolve-votes')->dailyAt('03:00');

// پاک‌سازی کدهای پیامکی قدیمی و توکن‌های منقضی
Schedule::command('pedigree:prune')->dailyAt('03:30');

// برای هاست‌هایی که supervisor ندارند: پردازش صف (فشرده‌سازی ویدیو) با cron
if (config('pedigree.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=2')
        ->everyMinute()
        ->withoutOverlapping(10);
}
