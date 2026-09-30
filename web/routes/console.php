<?php

use Illuminate\Support\Facades\Cache;
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

// اعلان تولدهای امروز و پیامک‌های تبریک خودکار: سر هر ساعت به وقت تهران (پس دقیقاً ساعت ۰۰:۰۰، اولین لحظه روز
// تولد یا مناسبت)؛ خودش ساعت تنظیم‌شده را رعایت می‌کند و اجراهای بعدی فقط جاماندگان را می‌فرستند
Schedule::command('pedigree:birthdays')->hourly()->timezone('Asia/Tehran')->withoutOverlapping(30);

// «سؤال روز» گروه خاندان برای زنده کردن خاطرات
Schedule::command('pedigree:group-prompt')->hourlyAt(7)->withoutOverlapping(10);

// پاک‌سازی کدهای پیامکی قدیمی و توکن‌های منقضی
Schedule::command('pedigree:prune')->dailyAt('03:30');

// هشدارها و یادآور مناسبت‌ها: هر دقیقه (زمان‌ها به وقت تهران حساب می‌شوند، مستقل از ساعت‌منطقه سرور)
Schedule::command('pedigree:reminders')->everyMinute()->withoutOverlapping(5);

// تقویم رسمی (holidayapi.ir): هر ده دقیقه حداکثر ۴۰ روز تا امسال و سال بعد کامل و به‌روز بماند (وقتی همه روزها
// همگام است فقط دو پرس‌وجوی کوچک پایگاه داده است و هیچ درخواستی بیرون نمی‌رود)
Schedule::command('pedigree:calendar-sync --limit=40')->everyTenMinutes()->withoutOverlapping(15);

// نشانه زنده بودن زمان‌بند برای «سلامت سرور» در پنل مدیریت
Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()->toIso8601String(), 86400))->everyFiveMinutes()->name('scheduler-heartbeat');

// برای هاست‌هایی که supervisor ندارند: پردازش صف (فشرده‌سازی ویدیو) با cron
if (config('pedigree.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=2')
        ->everyMinute()
        ->withoutOverlapping(10);
}
