<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\VoiceNote;
use App\Support\ErrorReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * انتقال عکس‌ها، ویدیوها و پیام‌های صوتی موجود از دیسک سرور به فضای ابری (یا برعکس).
 *
 * هر فایل اول کامل در مقصد نوشته و اندازه‌اش بررسی می‌شود، بعد رکورد به‌روز و در پایان فایل مبدأ پاک می‌شود؛
 * اگر وسط کار قطع شود، دفعه بعد از همان‌جا ادامه می‌دهد و هیچ فایلی گم نمی‌شود.
 */
class MediaMoveCommand extends Command
{
    protected $signature = 'pedigree:media-move {--to=s3 : دیسک مقصد (s3 یا media)} {--limit=500 : بیشترین تعداد در هر اجرا} {--keep : فایل مبدأ پاک نشود}';

    protected $description = 'انتقال فایل‌های رسانه به فضای ابری سازگار با S3 (یا برگرداندن به دیسک سرور)';

    public function handle(): int
    {
        $to = (string) $this->option('to');
        if (! in_array($to, ['s3', 'media'], true)) {
            $this->error('مقصد باید s3 یا media باشد.');

            return self::FAILURE;
        }
        $limit = max(1, min(10000, (int) $this->option('limit')));
        $moved = 0;
        $failed = 0;

        foreach ([Media::class, VoiceNote::class] as $model) {
            if ($moved >= $limit) {
                break;
            }
            $model::query()->where('disk', '!=', $to)->orderBy('created_at')->limit($limit - $moved)->get()
                ->each(function ($item) use ($to, &$moved, &$failed) {
                    $paths = array_values(array_filter(array_unique(array_merge([$item->path], array_values((array) ($item->variants ?? []))))));
                    try {
                        $from = Storage::disk($item->disk);
                        $target = Storage::disk($to);
                        foreach ($paths as $path) {
                            if (! $from->exists($path)) {
                                continue;
                            }
                            $stream = $from->readStream($path);
                            if (! is_resource($stream) || ! $target->writeStream($path, $stream)) {
                                throw new \RuntimeException('copy failed');
                            }
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                            if ($target->size($path) !== $from->size($path)) {
                                throw new \RuntimeException('size mismatch');
                            }
                        }
                        $old = $item->disk;
                        $item->forceFill(['disk' => $to])->save();
                        if (! $this->option('keep')) {
                            Storage::disk($old)->delete($paths);
                        }
                        $moved++;
                    } catch (Throwable $e) {
                        $failed++;
                        ErrorReporter::record($e);
                    }
                });
        }

        $this->info("moved {$moved}, failed {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
