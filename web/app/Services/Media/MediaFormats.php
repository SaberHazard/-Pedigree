<?php

namespace App\Services\Media;

/**
 * تشخیص نوع فایل از «امضای» بایت‌های اول (نه پسوند و نه ادعای مرورگر).
 *
 * عکس: تقریباً هر قالب رایج (JPEG، PNG، GIF، WebP، BMP، TIFF، HEIC/HEIF آیفون، AVIF، JPEG XL، JPEG 2000، PSD)
 * که همه به JPEG تبدیل می‌شوند. SVG، PDF و قالب‌های متنی/اسکریپتی هرگز پذیرفته نمی‌شوند.
 * ویدیو: از روی نوع MIME محتوا (finfo) در فهرست ظرف‌های رایج؛ همه به MP4 تبدیل می‌شوند.
 */
final class MediaFormats
{
    /** قالب‌هایی که GD مستقیم می‌خواند (بقیه با ابزار خط فرمان به PNG تبدیل می‌شوند) */
    public const GD_NATIVE = ['jpeg', 'png', 'gif', 'webp', 'bmp'];

    /** قالب‌هایی که با heif-convert خوانده می‌شوند */
    public const HEIF = ['heic', 'avif'];

    public const VIDEO_MIMES = [
        'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska', 'video/3gpp', 'video/3gpp2',
        'video/x-msvideo', 'video/avi', 'video/msvideo', 'video/x-ms-wmv', 'video/x-ms-asf', 'video/x-flv',
        'video/mpeg', 'video/mp2t', 'video/MP2T', 'video/ogg', 'video/x-m4v', 'application/ogg',
    ];

    public const IMAGE_LABEL = 'JPG، PNG، HEIC (آیفون)، WebP، GIF، BMP، TIFF، AVIF، JPEG XL و ...';

    /** نوع عکس از امضای فایل؛ null اگر عکسِ پشتیبانی‌شده نیست */
    public static function detectImage(string $path): ?string
    {
        $head = @file_get_contents($path, false, null, 0, 64);
        if ($head === false || strlen($head) < 12) {
            return null;
        }

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            str_starts_with($head, 'BM') && strlen($head) >= 26 => 'bmp',
            str_starts_with($head, "II*\0"), str_starts_with($head, "MM\0*") => 'tiff',
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true) => 'heic',
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['avif', 'avis'], true) => 'avif',
            str_starts_with($head, "\xFF\x0A"), str_starts_with($head, "\0\0\0\x0CJXL \r\n\x87\n") => 'jxl',
            str_starts_with($head, "\0\0\0\x0CjP  \r\n\x87\n"), str_starts_with($head, "\xFF\x4F\xFF\x51") => 'jp2',
            str_starts_with($head, '8BPS') => 'psd',
            default => null,
        };
    }

    /**
     * عکس یا ویدیو؟ (null = پشتیبانی نمی‌شود)
     *
     * @return 'image'|'video'|null
     */
    public static function classify(string $path, ?string $mime): ?string
    {
        if (self::detectImage($path) !== null) {
            return 'image';
        }
        $mime = strtolower((string) $mime);
        if (in_array($mime, array_map('strtolower', self::VIDEO_MIMES), true)) {
            return 'video';
        }

        return null;
    }

    /**
     * ابعاد تصویر HEIF/AVIF از جعبه‌های «ispe» (پیش از باز کردن کامل، برای جلوگیری از بمب تصویری)
     *
     * @return array{0:int,1:int}|null بزرگ‌ترین ابعاد پیدا شده
     */
    public static function heifDimensions(string $path): ?array
    {
        $data = @file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
        if (! $data) {
            return null;
        }
        $best = null;
        $offset = 0;
        while (($pos = strpos($data, 'ispe', $offset)) !== false && $pos + 16 <= strlen($data)) {
            $w = unpack('N', substr($data, $pos + 8, 4))[1];
            $h = unpack('N', substr($data, $pos + 12, 4))[1];
            if ($best === null || $w * $h > $best[0] * $best[1]) {
                $best = [$w, $h];
            }
            $offset = $pos + 4;
        }

        return $best;
    }
}
