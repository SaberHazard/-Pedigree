<?php

namespace App\Services\Media;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Models\VoiceNote;
use App\Support\PersianText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * پیام صوتی مثل تلگرام:
 *  - ضبط در مرورگر/اپ (WebM/Opus، MP4/AAC در آیفون، یا هر فایل صوتی)
 *  - بررسی محتوا (نه پسوند) و مدت با ffprobe
 *  - تبدیل با ffmpeg (بدون شبکه و فقط قالب‌های مجاز) به AAC تک‌کاناله ۲۴ کیلوهرتز حدود ۳۲ کیلوبیت:
 *    هر دقیقه حدود ۲۴۰ کیلوبایت، با کیفیت شفاف صدای انسان و قابل پخش روی همه گوشی‌ها و مرورگرها
 *  - متادیتا حذف می‌شود؛ فایل در فضای خصوصی و فقط با لینک امضاشده
 */
class VoiceService
{
    /** امضای ابتدای فایل‌های صوتی مجاز */
    private const SIGNATURES = [
        "\x1A\x45\xDF\xA3" => 'webm',  // WebM / Matroska (کروم، اندروید، فایرفاکس)
        'OggS' => 'ogg',               // Ogg Opus/Vorbis
        'RIFF' => 'wav',
        'ID3' => 'mp3',
        'fLaC' => 'flac',
        '#!AMR' => 'amr',              // گوشی‌های قدیمی
    ];

    private const FORMATS = 'matroska,webm,ogg,mov,mp4,m4a,3gp,mp3,wav,aac,flac,amr';

    /** @throws DomainException */
    public function store(User $user, UploadedFile $file, string $context, mixed $waveform = null): VoiceNote
    {
        if (! config('pedigree.voice.enabled', true)) {
            throw new DomainException('پیام صوتی را مدیر سایت خاموش کرده است.', 403, 'voice_off');
        }
        $maxKb = (int) config('pedigree.voice.max_kb', 10240);
        if (! $file->isValid() || $file->getSize() <= 0 || $file->getSize() > $maxKb * 1024) {
            throw new DomainException('فایل صوتی معتبر نیست یا بیش از حد بزرگ است.');
        }
        foreach ([['voice-m:', 12, 60], ['voice-d:', (int) config('pedigree.voice.daily_per_user', 150), 86400]] as [$prefix, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($prefix.$user->id, $max)) {
                throw new DomainException('تعداد پیام‌های صوتی شما زیاد شده است؛ کمی بعد دوباره امتحان کنید.', 429, 'voice_limit');
            }
        }

        $path = (string) $file->getRealPath();
        $kind = self::detect($path);
        if ($kind === null) {
            throw new DomainException('این فایل صوتی شناخته نشد.');
        }
        $seconds = $this->duration($path);
        $max = (int) config('pedigree.voice.max_seconds', 300);
        if ($seconds === null) {
            throw new DomainException('این فایل صوتی قابل پخش نیست.');
        }
        if ($seconds < 0.4) {
            throw new DomainException('پیام صوتی خیلی کوتاه است.');
        }
        if ($seconds > $max + 1) {
            throw new DomainException(PersianText::toPersianDigits('پیام صوتی حداکثر '.intdiv($max, 60).' دقیقه باشد.'));
        }
        RateLimiter::hit('voice-m:'.$user->id, 60);
        RateLimiter::hit('voice-d:'.$user->id, 86400);

        $disk = (string) config('pedigree.media.disk', 'media');
        $id = (string) Str::uuid7();
        $folder = 'voice/'.now()->format('Y/m');
        $out = tempnam(sys_get_temp_dir(), 'voice').'.m4a';
        try {
            if ($this->transcode($path, $out) && is_file($out) && filesize($out) > 0) {
                $stored = "{$folder}/{$id}.m4a";
                $mime = 'audio/mp4';
                Storage::disk($disk)->put($stored, (string) file_get_contents($out));
                $size = (int) filesize($out);
            } else {
                // بدون ffmpeg: همان فایل (فقط قالب‌های قابل پخش در مرورگرها)
                if (! in_array($kind, ['webm', 'ogg', 'mp4', 'mp3'], true)) {
                    throw new DomainException('تبدیل پیام صوتی روی سرور ممکن نشد.');
                }
                $ext = $kind === 'mp4' ? 'm4a' : $kind;
                $stored = "{$folder}/{$id}.{$ext}";
                $mime = ['webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'mp4' => 'audio/mp4', 'mp3' => 'audio/mpeg'][$kind];
                Storage::disk($disk)->putFileAs($folder, $file, "{$id}.{$ext}");
                $size = (int) $file->getSize();
            }
        } finally {
            @unlink($out);
            @unlink(substr($out, 0, -4));
        }

        $voice = new VoiceNote([
            'user_id' => $user->id,
            'context' => $context,
            'disk' => $disk,
            'path' => $stored,
            'mime' => $mime,
            'size' => $size,
            'duration_ms' => (int) round($seconds * 1000),
            'waveform' => self::cleanWaveform($waveform),
        ]);
        $voice->id = $id;
        $voice->save();

        return $voice;
    }

    public function delete(?VoiceNote $voice): void
    {
        if ($voice === null) {
            return;
        }
        try {
            Storage::disk($voice->disk)->delete($voice->path);
        } catch (Throwable $e) {
            Log::warning('Voice file delete failed', ['voice' => $voice->id, 'error' => $e->getMessage()]);
        }
        $voice->delete();
    }

    /** نوع فایل از روی چند بایت اول (نه پسوند) */
    public static function detect(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        foreach (self::SIGNATURES as $magic => $kind) {
            if (str_starts_with($head, $magic)) {
                return $kind === 'wav' && substr($head, 8, 4) !== 'WAVE' ? null : $kind;
            }
        }
        if (substr($head, 4, 4) === 'ftyp') {
            return 'mp4'; // MP4/M4A (آیفون) و 3GP
        }
        if (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) {
            return (ord($head[1]) & 0x06) === 0 ? 'aac' : 'mp3'; // ADTS AAC یا MP3 بدون برچسب
        }

        return null;
    }

    /** موج صدا: حداکثر ۶۴ ستون بین ۰ تا ۳۱ (برای نمایش مثل تلگرام) */
    public static function cleanWaveform(mixed $waveform): ?array
    {
        if (is_string($waveform)) {
            $waveform = json_decode($waveform, true);
        }
        if (! is_array($waveform) || ! array_is_list($waveform)) {
            return null;
        }
        $out = [];
        foreach (array_slice($waveform, 0, 64) as $v) {
            $out[] = is_numeric($v) ? max(0, min(31, (int) $v)) : 0;
        }

        return $out ?: null;
    }

    /** مدت فایل (ثانیه) با ffprobe؛ null = صدا ندارد یا خراب است */
    public function duration(string $path): ?float
    {
        $ffprobe = (string) config('pedigree.media.video.ffprobe', 'ffprobe');
        try {
            $p = new Process([$ffprobe, '-v', 'error', '-protocol_whitelist', 'file', '-format_whitelist', self::FORMATS,
                '-show_entries', 'stream=codec_type:format=duration', '-of', 'json', $path]);
            $p->setTimeout(30);
            $p->run();
        } catch (Throwable) {
            return null;
        }
        if (! $p->isSuccessful()) {
            return null;
        }
        $json = json_decode($p->getOutput(), true) ?: [];
        $hasAudio = collect($json['streams'] ?? [])->contains(fn ($s) => ($s['codec_type'] ?? null) === 'audio');
        $duration = (float) ($json['format']['duration'] ?? 0);

        return $hasAudio && $duration > 0 ? $duration : ($hasAudio ? $this->decodeDuration($path) : null);
    }

    /** بعضی ضبط‌های مرورگر (WebM) مدت را در سربرگ ندارند: مدت با خواندن کامل فایل */
    private function decodeDuration(string $path): ?float
    {
        $ffmpeg = (string) config('pedigree.media.video.ffmpeg', 'ffmpeg');
        try {
            $p = new Process([$ffmpeg, '-v', 'error', '-protocol_whitelist', 'file', '-format_whitelist', self::FORMATS, '-i', $path, '-vn', '-f', 'null', '-progress', 'pipe:1', '-']);
            $p->setTimeout(30);
            $p->run();
        } catch (Throwable) {
            return null;
        }
        if (preg_match_all('/out_time_us=(\d+)/', $p->getOutput(), $m) && $m[1]) {
            $us = (int) end($m[1]);

            return $us > 0 ? $us / 1_000_000 : null;
        }

        return null;
    }

    /** تبدیل به AAC تک‌کاناله کم‌حجم (بدون افت محسوس کیفیت صدای انسان) */
    private function transcode(string $in, string $out): bool
    {
        $ffmpeg = (string) config('pedigree.media.video.ffmpeg', 'ffmpeg');
        $max = (int) config('pedigree.voice.max_seconds', 300);
        try {
            $p = new Process([
                $ffmpeg, '-v', 'error', '-y', '-protocol_whitelist', 'file', '-format_whitelist', self::FORMATS, '-i', $in,
                '-vn', '-sn', '-dn', '-map_metadata', '-1', '-t', (string) ($max + 1),
                // حذف صدای خیلی بم (باد و میکروفون) و یکنواخت کردن بلندی صدا
                '-af', 'highpass=f=70,dynaudnorm=f=150:g=15',
                '-ac', '1', '-ar', '24000', '-c:a', 'aac', '-b:a', ((int) config('pedigree.voice.bitrate_kbps', 32)).'k',
                '-movflags', '+faststart', '-f', 'mp4', $out,
            ]);
            $p->setTimeout(120);
            $p->run();

            return $p->isSuccessful();
        } catch (Throwable $e) {
            Log::info('Voice transcode unavailable', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return false;
        }
    }
}
