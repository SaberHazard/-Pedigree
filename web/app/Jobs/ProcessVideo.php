<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\Media\ImageProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * فشرده‌سازی ویدیو در پس‌زمینه با ffmpeg.
 *
 * - تبدیل به MP4 (H.264 + AAC) که روی همه مرورگرها و گوشی‌ها پخش می‌شود
 * - مثل تلگرام: ضلع کوچک‌تر حداکثر 720 پیکسل، حداکثر ۳۰ فریم، CRF 26 با سقف بیت‌ریت
 *   (معمولاً ۵ تا ۱۰ برابر کم‌حجم‌تر از فایل گوشی، بدون افت محسوس)
 * - اگر فایل اصلی از قبل بهینه بوده و خروجی بزرگ‌تر شده، همان اصلی نگه داشته می‌شود
 * - فایل اصلی آپلودشده پس از تبدیل پاک می‌شود
 * - faststart: پخش ویدیو قبل از دانلود کامل شروع می‌شود
 * - ساخت پوستر (تصویر پیش‌نمایش) از ثانیه اول
 * اگر ffmpeg روی سرور نصب نباشد، ویدیو همان‌طور که هست نگه داشته می‌شود.
 */
class ProcessVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout;

    public function __construct(public string $mediaId)
    {
        $this->timeout = (int) config('pedigree.media.video.timeout', 1800);
    }

    public function handle(ImageProcessor $images): void
    {
        $media = Media::find($this->mediaId);
        if (! $media || ! $media->isVideo() || $media->status === Media::STATUS_REJECTED) {
            return;
        }

        $media->processing = 'processing';
        $media->save();

        $disk = Storage::disk($media->disk);
        $workDir = storage_path('app/tmp/'.$media->id);
        @mkdir($workDir, 0775, true);

        try {
            // فایل را برای ffmpeg به صورت محلی در دسترس قرار بده (برای S3 هم کار می‌کند)
            $ext = pathinfo($media->path, PATHINFO_EXTENSION) ?: 'mp4';
            $source = $workDir.'/source.'.$ext;
            file_put_contents($source, $disk->readStream($media->path));

            $config = config('pedigree.media.video');
            if (! $this->ffmpegAvailable($config['ffmpeg'])) {
                Log::notice('ffmpeg not found; video stored without compression', ['media' => $media->id]);
                $media->processing = 'ready';
                $media->save();

                return;
            }

            // ۱. اطلاعات ویدیو
            $probe = $this->probe($config['ffprobe'], $source);

            // ۲. فشرده‌سازی به سبک تلگرام: ضلع کوچک‌تر حداکثر ۷۲۰، حداکثر ۳۰ فریم، H.264 با CRF و سقف بیت‌ریت
            $output = $workDir.'/out.mp4';
            $this->run(self::encodeCommand($config, $probe, $source, $output));

            // اگر ویدیوی اصلی از قبل بهینه بوده و خروجی بزرگ‌تر شده، همان اصلی (بدون متادیتا) نگه داشته می‌شود
            clearstatcache();
            if (filesize($output) >= filesize($source) && self::alreadyEfficient($config, $probe)) {
                $remuxed = $workDir.'/remux.mp4';
                try {
                    $this->run([
                        $config['ffmpeg'], '-y', '-nostdin', ...self::inputGuard(), '-i', $source,
                        '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn', '-map_metadata', '-1',
                        '-c', 'copy', '-movflags', '+faststart', $remuxed,
                    ]);
                    clearstatcache();
                    if (is_file($remuxed) && filesize($remuxed) > 0 && filesize($remuxed) < filesize($output)) {
                        rename($remuxed, $output);
                    }
                } catch (Throwable $e) {
                    Log::info('Video remux skipped: '.$e->getMessage());
                }
            }

            $folder = dirname($media->path);
            $newPath = $folder.'/'.$media->id.'.mp4';
            $disk->put($newPath, fopen($output, 'r'));
            if ($newPath !== $media->path) {
                $disk->delete($media->path);
            }

            // ۳. پوستر
            $variants = $media->variants ?? [];
            $frame = $workDir.'/frame.jpg';
            try {
                $this->run([$config['ffmpeg'], '-y', '-nostdin', ...self::inputGuard(), '-ss', '1', '-i', $output, '-frames:v', '1', $frame]);
                if (! is_file($frame)) {
                    $this->run([$config['ffmpeg'], '-y', '-nostdin', ...self::inputGuard(), '-i', $output, '-frames:v', '1', $frame]);
                }
                $posters = $images->poster($frame);
                foreach ($posters as $name => $binary) {
                    $variants[$name] = "{$folder}/{$media->id}_{$name}.webp";
                    $disk->put($variants[$name], $binary);
                }
            } catch (Throwable $e) {
                Log::warning('Video poster failed: '.$e->getMessage());
            }

            clearstatcache();
            $final = $this->probe($config['ffprobe'], $output) ?: $probe;
            $media->path = $newPath;
            $media->mime = 'video/mp4';
            $media->size = (int) filesize($output);
            $media->variants = $variants;
            $media->duration = $final['duration'] ?? $probe['duration'] ?? null;
            $media->width = $final['width'] ?? null;
            $media->height = $final['height'] ?? null;
            $media->processing = 'ready';
            $media->save();
        } finally {
            $this->cleanup($workDir);
        }
    }

    public function failed(?Throwable $e): void
    {
        Media::whereKey($this->mediaId)->update(['processing' => 'failed']);
        Log::error('Video processing failed', ['media' => $this->mediaId, 'error' => $e?->getMessage()]);
    }

    /**
     * فرمان ffmpeg فشرده‌سازی
     *
     * - ضلع کوچک‌تر حداکثر max_height (۷۲۰): ویدیوی عمودی گوشی 1080×1920 می‌شود 720×1280، نه 405×720
     * - فریم‌ریت بالاتر از ۳۰ به ۳۰ کاهش می‌یابد
     * - CRF (کیفیت ثابت) با سقف بیت‌ریت تا صحنه‌های شلوغ حجم را منفجر نکنند
     * - صدا AAC استریو ۹۶ کیلوبیت؛ متادیتا (از جمله موقعیت مکانی) حذف می‌شود
     */
    public static function encodeCommand(array $config, array $probe, string $source, string $output): array
    {
        $short = max(240, (int) ($config['max_height'] ?? 720));
        $crf = min(35, max(18, (int) ($config['crf'] ?? 26)));
        // سقف بیت‌ریت متناسب با وضوح (کیلوبیت)
        $maxrate = $short >= 1080 ? 4500 : ($short >= 720 ? 2200 : 1100);

        $filters = [
            "scale=w='if(gte(iw,ih),-2,trunc(min(iw,{$short})/2)*2)':h='if(gte(iw,ih),trunc(min(ih,{$short})/2)*2,-2)'",
        ];
        if (($probe['fps'] ?? 0) > 30.5) {
            $filters[] = 'fps=30';
        }

        return [
            $config['ffmpeg'], '-y', '-nostdin', ...self::inputGuard(), '-i', $source,
            '-t', (string) max(1, (int) ($config['max_duration'] ?? 3600)),
            '-threads', (string) max(1, (int) ($config['threads'] ?? 2)),
            '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn', '-map_metadata', '-1',
            '-vf', implode(',', $filters),
            '-c:v', 'libx264', '-preset', 'medium', '-crf', (string) $crf,
            '-maxrate', "{$maxrate}k", '-bufsize', ($maxrate * 2).'k',
            '-pix_fmt', 'yuv420p', '-profile:v', 'high',
            '-c:a', 'aac', '-b:a', '96k', '-ac', '2',
            '-movflags', '+faststart',
            $output,
        ];
    }

    /** ویدیوی اصلی همین حالا H.264/AAC در MP4 و در محدوده وضوح و فریم‌ریت است؟ */
    public static function alreadyEfficient(array $config, array $probe): bool
    {
        $short = min((int) ($probe['width'] ?? PHP_INT_MAX), (int) ($probe['height'] ?? PHP_INT_MAX));

        return ($probe['vcodec'] ?? null) === 'h264'
            && in_array($probe['acodec'] ?? null, ['aac', null], true)
            && str_contains((string) ($probe['format'] ?? ''), 'mp4')
            && $short <= (int) ($config['max_height'] ?? 720)
            && ($probe['fps'] ?? 0) <= 30.5
            && ($probe['duration'] ?? PHP_INT_MAX) <= (int) ($config['max_duration'] ?? 3600);
    }

    /**
     * محدودیت‌های ورودی ffmpeg/ffprobe برای فایل‌های کاربر:
     * فقط خواندن از فایل محلی (بدون شبکه) و فقط قالب‌های ویدیویی رایج؛ فایل دست‌کاری‌شده‌ای که
     * داخلش فهرست پخش HLS یا concat گذاشته شده نمی‌تواند فایل‌های سرور را بخواند یا به شبکه وصل شود.
     */
    public static function inputGuard(): array
    {
        return [
            '-protocol_whitelist', 'file',
            '-format_whitelist', 'mov,mp4,m4a,3gp,3g2,mj2,matroska,webm,avi',
        ];
    }

    private function ffmpegAvailable(string $binary): bool
    {
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function probe(string $ffprobe, string $file): array
    {
        try {
            $process = new Process([$ffprobe, '-v', 'error', ...self::inputGuard(), '-show_entries',
                'stream=codec_type,codec_name,width,height,avg_frame_rate:format=duration,format_name', '-of', 'json', $file]);
            $process->setTimeout(60);
            $process->run();

            return self::parseProbe(json_decode($process->getOutput(), true) ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    /** خلاصه خروجی ffprobe */
    public static function parseProbe(array $json): array
    {
        $video = collect($json['streams'] ?? [])->firstWhere('codec_type', 'video') ?? [];
        $audio = collect($json['streams'] ?? [])->firstWhere('codec_type', 'audio');
        $fps = null;
        if (preg_match('#^(\d+)/(\d+)$#', (string) ($video['avg_frame_rate'] ?? ''), $m) && (int) $m[2] > 0) {
            $fps = round((int) $m[1] / (int) $m[2], 2);
        }

        return [
            'duration' => isset($json['format']['duration']) ? (int) round((float) $json['format']['duration']) : null,
            'width' => $video['width'] ?? null,
            'height' => $video['height'] ?? null,
            'fps' => $fps,
            'vcodec' => $video['codec_name'] ?? null,
            'acodec' => $audio['codec_name'] ?? null,
            'format' => $json['format']['format_name'] ?? null,
        ];
    }

    private function run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->mustRun();
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
