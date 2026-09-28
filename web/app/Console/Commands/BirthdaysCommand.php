<?php

namespace App\Console\Commands;

use App\Services\Occasions\BirthdayService;
use App\Services\Sms\GreetingService;
use Illuminate\Console\Command;

/**
 * هر ساعت اجرا می‌شود (زمان‌بند)؛ از ساعت تنظیم‌شده به بعد:
 *  - اعلان «امروز تولد فلانی است» به اعضا (هر تولد سالی یک بار)
 *  - پیامک تبریک خودکار از طرف اعضایی که آن را روشن کرده‌اند (هر فرستنده به هر گیرنده سالی یک بار)
 */
class BirthdaysCommand extends Command
{
    protected $signature = 'pedigree:birthdays {--force : بدون توجه به ساعت تنظیم‌شده}';

    protected $description = 'اعلان تولدهای امروز و ارسال پیامک‌های تبریک خودکار';

    public function handle(BirthdayService $birthdays, GreetingService $greetings): int
    {
        $hour = (int) now('Asia/Tehran')->format('G');
        $force = (bool) $this->option('force');

        if ($force || $hour >= (int) config('pedigree.birthdays.notify_hour', 8)) {
            $count = $birthdays->notifyToday();
            $this->info("birthday notifications: {$count}");
        }
        if ($force || $hour >= (int) config('pedigree.member_sms.send_hour', 9)) {
            $stats = $greetings->autoSendToday();
            $this->info("auto greetings: sent {$stats['sent']}, skipped {$stats['skipped']}, failed {$stats['failed']}");
        }

        return self::SUCCESS;
    }
}
