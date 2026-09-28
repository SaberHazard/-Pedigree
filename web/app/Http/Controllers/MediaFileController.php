<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * تحویل فایل‌های رسانه.
 *
 * این مسیر با لینک امضاشده محافظت می‌شود (امضا در API برای افراد مجاز ساخته می‌شود)
 * پس نیازی به کوکی یا توکن نیست و در <img> و <video> و اپ موبایل هم کار می‌کند.
 * ویدیوها از Range پشتیبانی می‌کنند (جلو/عقب بردن ویدیو).
 */
class MediaFileController extends Controller
{
    public function show(Media $media, string $variant): Response
    {
        abort_unless(in_array($variant, ['original', 'thumb', 'medium', 'poster'], true), 404);
        abort_if($media->status === Media::STATUS_REJECTED, 404);

        $path = $media->pathFor($variant) ?? $media->path;
        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($path), 404);

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => $media->mime,
        };
        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        // دیسک محلی: ارسال مستقیم فایل (با پشتیبانی Range)
        $config = config('filesystems.disks.'.$media->disk);
        if (($config['driver'] ?? null) === 'local') {
            return response()->file($disk->path($path), $headers);
        }

        // دیسک ابری (S3 و ...): هدایت به لینک موقت
        return redirect()->away($disk->temporaryUrl($path, now()->addMinutes(30)));
    }
}
