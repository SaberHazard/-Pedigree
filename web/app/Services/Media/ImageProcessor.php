<?php

namespace App\Services\Media;

use App\Exceptions\DomainException;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * فشرده‌سازی عکس‌ها مثل تلگرام و اینستاگرام.
 *
 * - هر قالبی (JPEG، PNG، HEIC آیفون، WebP، GIF، BMP، TIFF، AVIF، JPEG XL، JPEG 2000، PSD) پذیرفته و
 *   به JPEG پیشرونده (progressive) با کیفیت ۸۴ و حداکثر ۲۵۶۰ پیکسل تبدیل می‌شود:
 *   از نظر چشمی بدون افت، معمولاً ۳ تا ۱۰ برابر کم‌حجم‌تر و روی همه دستگاه‌ها قابل باز شدن
 * - قالب‌هایی که کتابخانه GD نمی‌خواند با heif-convert (HEIC/AVIF) یا ffmpeg به PNG موقت تبدیل می‌شوند
 * - چرخش خودکار بر اساس EXIF و سپس حذف کامل متادیتا (مختصات GPS و ... پاک می‌شود)
 * - بازنویسی کامل پیکسل‌ها: فایل‌های مخرب جاسازی‌شده در عکس از بین می‌روند
 * - پیش از باز کردن، ابعاد بررسی می‌شود («بمب تصویری» سرور را از کار نمی‌اندازد)
 */
class ImageProcessor
{
    private ImageManager $manager;

    public function __construct()
    {
        $driver = extension_loaded('imagick') ? ImagickDriver::class : GdDriver::class;
        // پس‌زمینه سفید برای عکس‌های شفاف (PNG) هنگام تبدیل به JPEG
        $this->manager = new ImageManager($driver, autoOrientation: true, decodeAnimation: false, backgroundColor: 'ffffff', strip: true);
    }

    /**
     * @return array{main: string, variants: array<string,string>, width:int, height:int}
     *                                                                                    خروجی باینری JPEG هر نسخه (کلید = نام نسخه)
     */
    public function process(string $sourcePath): array
    {
        $config = config('pedigree.media.image');
        @ini_set('memory_limit', '768M');

        $work = null;
        try {
            [$image, $work] = $this->open($sourcePath, (int) ($config['max_megapixels'] ?? 60));

            $max = (int) ($config['max_dimension'] ?? 2560);
            $quality = max(60, min(95, (int) ($config['quality'] ?? 84)));

            $image->scaleDown($max, $max);
            $width = $image->width();
            $height = $image->height();
            $main = $this->jpeg($image, $quality);

            $variants = [];
            foreach ($config['variants'] as $name => $variant) {
                $copy = $this->manager->decodeBinary($main);
                $size = (int) $variant['size'];
                if ($variant['crop']) {
                    $copy->coverDown($size, $size);
                } else {
                    $copy->scaleDown($size, $size);
                }
                $variants[$name] = $this->jpeg($copy, min($quality, 82));
            }

            return ['main' => $main, 'variants' => $variants, 'width' => $width, 'height' => $height];
        } finally {
            if ($work) {
                $this->cleanup($work);
            }
        }
    }

    /**
     * باز کردن هر قالب پشتیبانی‌شده
     *
     * @return array{0: ImageInterface, 1: ?string} [تصویر، پوشه موقت برای پاک کردن]
     */
    private function open(string $path, int $maxMegapixels): array
    {
        $format = MediaFormats::detectImage($path);
        if ($format === null) {
            throw new DomainException('این فایل عکس نیست یا قالب آن پشتیبانی نمی‌شود ('.MediaFormats::IMAGE_LABEL.').');
        }

        // ۱) مستقیم با کتابخانه تصویر (GD یا Imagick)
        if (in_array($format, MediaFormats::GD_NATIVE, true) || $format === 'avif' || extension_loaded('imagick')) {
            try {
                $this->assertSafeDimensions($path, $maxMegapixels);

                return [$this->manager->decodePath($path), null];
            } catch (DomainException $e) {
                if (in_array($format, MediaFormats::GD_NATIVE, true)) {
                    throw $e;
                }
            } catch (Throwable) {
                if (in_array($format, MediaFormats::GD_NATIVE, true)) {
                    throw new DomainException('این عکس خراب است یا قابل خواندن نیست.');
                }
            }
        }

        // ۲) تبدیل به PNG موقت با ابزار خط فرمان (HEIC آیفون، TIFF، PSD، JPEG XL ...)
        $work = storage_path('app/tmp/img-'.Str::random(20));
        @mkdir($work, 0700, true);
        try {
            $png = $this->convertWithCli($path, $format, $work, $maxMegapixels);
            $this->assertSafeDimensions($png, $maxMegapixels);

            return [$this->manager->decodePath($png), $work];
        } catch (DomainException $e) {
            $this->cleanup($work);
            throw $e;
        } catch (Throwable) {
            $this->cleanup($work);
            throw new DomainException('این عکس قابل تبدیل نیست؛ آن را به صورت JPG یا PNG ذخیره و دوباره آپلود کنید.');
        }
    }

    private function convertWithCli(string $path, string $format, string $work, int $maxMegapixels): string
    {
        $out = $work.'/out.png';
        if (in_array($format, MediaFormats::HEIF, true)) {
            $dims = MediaFormats::heifDimensions($path);
            if ($dims === null || $dims[0] > 30000 || $dims[1] > 30000 || $maxMegapixels * 1_000_000 < $dims[0] * $dims[1]) {
                throw new DomainException('ابعاد تصویر بیش از حد بزرگ یا نامعتبر است.');
            }
            $input = $work.'/in.'.$format;
            copy($path, $input);
            $this->run([(string) config('pedigree.media.image.heif_convert', 'heif-convert'), '-q', '100', $input, $out]);
        } else {
            // ffmpeg فقط فایل محلی و فقط قالب‌های تصویری را باز می‌کند (بدون شبکه، بدون فهرست پخش)
            $ext = ['tiff' => 'tif', 'jxl' => 'jxl', 'jp2' => 'jp2', 'psd' => 'psd', 'avif' => 'avif', 'heic' => 'heic'][$format] ?? 'img';
            $input = $work.'/in.'.$ext;
            copy($path, $input);
            $this->run([
                (string) config('pedigree.media.video.ffmpeg', 'ffmpeg'), '-y', '-nostdin', '-loglevel', 'error',
                '-protocol_whitelist', 'file', '-format_whitelist', 'image2,tiff_pipe,psd_pipe,jpegxl_pipe,j2k_pipe',
                '-max_pixels', (string) ($maxMegapixels * 1_000_000),
                '-i', $input, '-frames:v', '1', '-update', '1', $out,
            ]);
        }
        if (! is_file($out) || filesize($out) === 0) {
            throw new DomainException('این عکس قابل تبدیل نیست؛ آن را به صورت JPG یا PNG ذخیره و دوباره آپلود کنید.');
        }

        return $out;
    }

    private function run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(90);
        $process->mustRun();
    }

    private function jpeg(ImageInterface $image, int $quality): string
    {
        return (string) $image->encode(new JpegEncoder(quality: $quality, progressive: true, strip: true));
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
            throw new DomainException('این فایل تصویری قابل خواندن نیست یا فرمت آن پشتیبانی نمی‌شود.');
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

            return $this->jpeg($image, 82);
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
        $poster = $this->jpeg($image, 82);
        $thumb = $this->manager->decodeBinary($poster)->coverDown(320, 320);

        return [
            'poster' => $poster,
            'thumb' => $this->jpeg($thumb, 80),
        ];
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
