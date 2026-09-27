<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * ساخت لینک امضاشده برای فایل‌های رسانه.
 *
 * زمان انقضا به ابتدای یک «پنجره زمانی» گرد می‌شود تا لینک یک فایل برای چند ساعت
 * ثابت بماند و مرورگر/اپ بتواند آن را کش کند.
 */
final class MediaUrl
{
    public static function for(Media $media, string $variant = 'original'): string
    {
        $ttlHours = (int) config('pedigree.media.url_ttl_hours', 24);
        $window = 6 * 3600;
        $expires = (int) (ceil((time() + $ttlHours * 3600) / $window) * $window);

        return URL::temporarySignedRoute(
            'media.file',
            Carbon::createFromTimestamp($expires),
            ['media' => $media->id, 'variant' => $variant],
            absolute: false,
        );
    }
}
