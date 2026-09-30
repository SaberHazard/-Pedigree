<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderService;
use App\Support\ErrorReporter;
use Illuminate\Console\Command;
use Throwable;

class RemindersCommand extends Command
{
    protected $signature = 'pedigree:reminders';

    protected $description = 'ارسال هشدارهای سررسیده و یادآوری روزانه مناسبت‌ها (هر دقیقه، وقت تهران)';

    public function handle(ReminderService $reminders): int
    {
        try {
            $stats = $reminders->dispatchDue();
            if ($stats['sent'] || $stats['digests'] || $stats['failed']) {
                $this->info("reminders: sent {$stats['sent']}, digests {$stats['digests']}, failed {$stats['failed']}");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            ErrorReporter::record($e);

            return self::FAILURE;
        }
    }
}
