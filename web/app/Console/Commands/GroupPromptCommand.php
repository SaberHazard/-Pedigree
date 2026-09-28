<?php

namespace App\Console\Commands;

use App\Services\Group\GroupService;
use Illuminate\Console\Command;

/**
 * «سؤال روز» گروه خاندان (هر ساعت اجرا می‌شود؛ روزی یک بار از ساعت تنظیم‌شده به بعد پست می‌کند)
 */
class GroupPromptCommand extends Command
{
    protected $signature = 'pedigree:group-prompt {--force : بدون توجه به ساعت}';

    protected $description = 'Post the daily memory question to the clan group';

    public function handle(GroupService $group): int
    {
        $message = $group->postDailyPrompt((bool) $this->option('force'));
        $this->info($message ? 'posted: '.$message->body : 'nothing to do');

        return self::SUCCESS;
    }
}
