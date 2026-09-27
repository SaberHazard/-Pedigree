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
 * - کاهش ارتفاع به حداکثر 720 پیکسل با CRF 25 (کیفیت خوب، حجم کم)
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

            // ۲. فشرده‌سازی
            $output = $workDir.'/out.mp4';
            $maxHeight = (int) $config['max_height'];
            $this->run([
                $config['ffmpeg'], '-y', '-i', $source,
                '-vf', "scale=-2:'min({$maxHeight},ih)'",
                '-c:v', 'libx264', '-preset', 'medium', '-crf', (string) $config['crf'],
                '-pix_fmt', 'yuv420p', '-profile:v', 'high',
                '-c:a', 'aac', '-b:a', '128k',
                '-movflags', '+faststart',
                $output,
            ]);

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
                $this->run([$config['ffmpeg'], '-y', '-ss', '1', '-i', $output, '-frames:v', '1', $frame]);
                if (! is_file($frame)) {
                    $this->run([$config['ffmpeg'], '-y', '-i', $output, '-frames:v', '1', $frame]);
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
            $media->path = $newPath;
            $media->mime = 'video/mp4';
            $media->size = (int) filesize($output);
            $media->variants = $variants;
            $media->duration = $probe['duration'] ?? null;
            $media->width = $probe['width'] ?? null;
            $media->height = $probe['height'] ?? null;
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
            $process = new Process([$ffprobe, '-v', 'error', '-select_streams', 'v:0', '-show_entries',
                'stream=width,height:format=duration', '-of', 'json', $file]);
            $process->setTimeout(60);
            $process->run();
            $json = json_decode($process->getOutput(), true) ?: [];

            return [
                'duration' => isset($json['format']['duration']) ? (int) round((float) $json['format']['duration']) : null,
                'width' => $json['streams'][0]['width'] ?? null,
                'height' => $json['streams'][0]['height'] ?? null,
            ];
        } catch (Throwable) {
            return [];
        }
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
