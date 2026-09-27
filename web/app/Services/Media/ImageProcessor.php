<?php

namespace App\Services\Media;

use App\Exceptions\DomainException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * فشرده‌سازی عکس‌ها.
 *
 * - تبدیل به WebP با کیفیت ۸۵ (از نظر چشمی بدون افت، معمولاً ۳ تا ۱۰ برابر کم‌حجم‌تر از JPEG موبایل)
 * - چرخش خودکار بر اساس EXIF و سپس حذف کامل متادیتا (مختصات GPS و ... پاک می‌شود)
 * - بازنویسی کامل پیکسل‌ها: فایل‌های مخرب جاسازی‌شده در عکس از بین می‌روند
 * - ساخت نسخه‌های کوچک (thumb مربعی برای دایره‌های درخت، medium برای گالری)
 */
class ImageProcessor
{
    private ImageManager $manager;

    public function __construct()
    {
        $driver = extension_loaded('imagick') ? ImagickDriver::class : GdDriver::class;
        $this->manager = new ImageManager($driver, autoOrientation: true, decodeAnimation: false, strip: true);
    }

    /**
     * @return array{main: string, variants: array<string,string>, width:int, height:int}
     *                                                                                    خروجی باینری هر نسخه (کلید = نام نسخه)
     */
    public function process(string $sourcePath): array
    {
        $config = config('pedigree.media.image');
        $this->assertSafeDimensions($sourcePath, (int) ($config['max_megapixels'] ?? 60));
        @ini_set('memory_limit', '768M');

        try {
            $image = $this->manager->decodePath($sourcePath);
        } catch (Throwable) {
            throw new DomainException('این فایل تصویری قابل خواندن نیست یا فرمت آن پشتیبانی نمی‌شود (JPG، PNG، WebP).');
        }

        $max = (int) $config['max_dimension'];
        $quality = (int) $config['quality'];

        $image->scaleDown($max, $max);
        $width = $image->width();
        $height = $image->height();
        $main = (string) $image->encode(new WebpEncoder(quality: $quality, strip: true));

        $variants = [];
        foreach ($config['variants'] as $name => $variant) {
            $copy = $this->manager->decodeBinary($main);
            $size = (int) $variant['size'];
            if ($variant['crop']) {
                $copy->coverDown($size, $size);
            } else {
                $copy->scaleDown($size, $size);
            }
            $variants[$name] = (string) $copy->encode(new WebpEncoder(quality: min($quality, 82), strip: true));
        }

        return ['main' => $main, 'variants' => $variants, 'width' => $width, 'height' => $height];
    }

    /**
     * بررسی ابعاد تصویر پیش از باز کردن کامل آن («بمب فشرده‌سازی»: یک فایل کوچک با ابعاد
     * بسیار بزرگ که هنگام باز شدن چند گیگابایت حافظه می‌گیرد و سرور را از کار می‌اندازد).
     */
    public function assertSafeDimensions(string $path, int $maxMegapixels): void
    {
        $size = @getimagesize($path);
        $width = $size[0] ?? 0;
        $height = $size[1] ?? 0;
        if ((! $width || ! $height) && extension_loaded('imagick')) {
            try {
                $probe = new \Imagick;
                $probe->pingImage($path);
                $width = $probe->getImageWidth();
                $height = $probe->getImageHeight();
                $probe->clear();
            } catch (Throwable) {
                $width = $height = 0;
            }
        }
        if (! $width || ! $height) {
            throw new DomainException('این فایل تصویری قابل خواندن نیست یا فرمت آن پشتیبانی نمی‌شود (JPG، PNG، WebP).');
        }
        if ($width > 30000 || $height > 30000 || $width * $height > $maxMegapixels * 1_000_000) {
            throw new DomainException('ابعاد تصویر بیش از حد بزرگ است (حداکثر '.$maxMegapixels.' مگاپیکسل).');
        }
    }

    /** تصویر کوچک مربعی از داده باینری (پیش‌نمایش عکس شبکه‌های اجتماعی؛ پیکسل‌ها کاملاً بازنویسی می‌شوند) */
    public function thumbnailFromBinary(string $binary, int $size = 256): ?string
    {
        $temp = tempnam(sys_get_temp_dir(), 'thumb');
        try {
            file_put_contents($temp, $binary);
            $this->assertSafeDimensions($temp, 40);
            $image = $this->manager->decodePath($temp);
            $image->coverDown($size, $size);

            return (string) $image->encode(new WebpEncoder(quality: 80, strip: true));
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($temp);
        }
    }

    /** ساخت پوستر/تصویر کوچک از یک فریم ویدیو */
    public function poster(string $framePath): array
    {
        $this->assertSafeDimensions($framePath, 60);
        $image = $this->manager->decodePath($framePath);
        $image->scaleDown(1280, 1280);
        $poster = (string) $image->encode(new WebpEncoder(quality: 80, strip: true));
        $thumb = $this->manager->decodeBinary($poster)->coverDown(320, 320);

        return [
            'poster' => $poster,
            'thumb' => (string) $thumb->encode(new WebpEncoder(quality: 78, strip: true)),
        ];
    }
}
