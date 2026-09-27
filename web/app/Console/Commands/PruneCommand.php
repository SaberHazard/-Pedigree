<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * پاک‌سازی داده‌های موقت قدیمی
 */
class PruneCommand extends Command
{
    protected $signature = 'pedigree:prune';

    protected $description = 'Delete old OTP codes, expired API tokens and temp files';

    public function handle(): int
    {
        $otp = OtpCode::where('created_at', '<', now()->subDays(2))->delete();
        $tokens = PersonalAccessToken::where('expires_at', '<', now())->delete();

        // فایل‌های موقت پردازش ویدیو که بیش از یک روز مانده‌اند
        foreach (glob(storage_path('app/tmp/*')) ?: [] as $dir) {
            if (is_dir($dir) && filemtime($dir) < time() - 86400) {
                array_map('unlink', glob($dir.'/*') ?: []);
                @rmdir($dir);
            }
        }

        $this->info("OTP codes: {$otp}, tokens: {$tokens}");

        return self::SUCCESS;
    }
}
