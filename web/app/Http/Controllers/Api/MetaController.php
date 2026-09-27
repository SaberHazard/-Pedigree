<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * تنظیمات عمومی برای کلاینت‌ها (وب، اندروید، iOS) پیش از ورود.
 */
class MetaController extends Controller
{
    public function bootstrap(): JsonResponse
    {
        return response()->json([
            'site_name' => config('pedigree.site_name'),
            'version' => config('app.version', '1.0.0'),
            'registration_enabled' => (bool) config('pedigree.registration.enabled'),
            'guest_view' => (bool) config('pedigree.guest_view'),
            'otp' => [
                'length' => (int) config('pedigree.otp.length'),
                'ttl' => (int) config('pedigree.otp.ttl'),
                'cooldown' => (int) config('pedigree.otp.resend_cooldown'),
            ],
            'password_min' => (int) config('pedigree.password.min_length'),
            'media' => [
                'image_max_kb' => (int) config('pedigree.media.image.max_upload_kb'),
                'video_max_kb' => (int) config('pedigree.media.video.max_upload_kb'),
                'video_enabled' => (bool) config('pedigree.media.video.enabled'),
                'image_mimes' => config('pedigree.media.image.mimes'),
                'video_mimes' => config('pedigree.media.video.mimes'),
            ],
            'tree' => [
                'default_depth' => (int) config('pedigree.tree.default_depth'),
                'max_depth' => (int) config('pedigree.tree.max_depth'),
            ],
            'dev_sms' => config('pedigree.sms.driver') === 'log',
        ]);
    }
}
