<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AssistantService;
use App\Services\Ai\FamilyFacts;
use App\Services\Ai\PhotoRestorer;
use App\Services\Ai\VoiceAi;
use App\Services\Social\SocialProfileFetcher;
use App\Support\Countries;
use App\Support\SocialNetworks;
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
            // نشانی سایت برای پانویس خروجی‌ها (PDF، عکس، SVG) و اشتراک‌گذاری
            'site_url' => rtrim((string) config('app.url'), '/'),
            'version' => config('app.version', '1.0.0'),
            'registration_enabled' => (bool) config('pedigree.registration.enabled'),
            'registration' => [
                'require_national_code' => (bool) config('pedigree.registration.require_national_code', true),
                'allow_without_national_code' => (bool) config('pedigree.registration.allow_without_national_code', true),
                'require_approval' => (bool) config('pedigree.registration.require_approval', true),
            ],
            'otp' => [
                'length' => (int) config('pedigree.otp.length'),
                'ttl' => (int) config('pedigree.otp.ttl'),
                'cooldown' => (int) config('pedigree.otp.resend_cooldown'),
            ],
            'password_min' => (int) config('pedigree.password.min_length'),
            'media' => [
                'image_max_kb' => (int) config('pedigree.media.image.max_upload_kb'),
                'video_max_kb' => (int) config('pedigree.media.video.max_upload_mb') * 1024,
                'video_enabled' => (bool) config('pedigree.media.video.enabled'),
                // همه قالب‌های عکس (HEIC آیفون، TIFF ...) و ویدیو؛ سرور به JPEG/MP4 تبدیل می‌کند
                'accept' => 'image/*,.heic,.heif,.avif,.tif,.tiff,.jxl,.jp2,.psd'.(config('pedigree.media.video.enabled') ? ',video/*,.mkv,.avi,.wmv,.flv,.3gp,.mts,.m2ts,.ts' : ''),
            ],
            'tree' => [
                'default_depth' => (int) config('pedigree.tree.default_depth'),
                'max_depth' => (int) config('pedigree.tree.max_depth'),
            ],
            'dev_sms' => config('pedigree.sms.driver') === 'log',
            'voice' => [
                'enabled' => (bool) config('pedigree.voice.enabled', true),
                'max_seconds' => (int) config('pedigree.voice.max_seconds', 300),
            ],

            // گزینه‌های فرم پروفایل کامل
            'profile' => [
                'education_levels' => config('pedigree.profile.education_levels'),
                'academic_ranks' => config('pedigree.profile.academic_ranks'),
                'blood_types' => config('pedigree.profile.blood_types'),
                'education_field_groups' => array_map(fn ($g) => ['label' => $g['label'], 'engineer' => (bool) $g['engineer']], config('pedigree.profile.education_field_groups', [])),
                'honorifics' => [
                    'doctor_levels' => config('pedigree.profile.honorifics.doctor_levels', []),
                    'doctor_ranks' => config('pedigree.profile.honorifics.doctor_ranks', []),
                    'engineer_levels' => config('pedigree.profile.honorifics.engineer_levels', []),
                    'engineer_keywords' => config('pedigree.profile.honorifics.engineer_keywords', []),
                    'before' => config('pedigree.profile.honorifics.before', []),
                ],
                // شبکه‌های اجتماعی به ترتیب نمایش (بدون الگوها و دامنه‌ها)
                'social_networks' => collect(SocialNetworks::all())->map(fn ($n, $key) => [
                    'label' => $n['label'],
                    'kind' => $n['kind'] ?? 'handle',
                    'url' => $n['url'],
                    // دریافت خودکار عکس با تنظیمات فعلی ممکن است؟ (ایکس و یوتیوب فقط با کلید)
                    'fetch' => app(SocialProfileFetcher::class)->supports($key),
                ])->all(),
                'social_avatar_priority' => config('pedigree.profile.social_avatar_priority', []),
                'visibility_levels' => [
                    'all' => 'همه اعضای خاندان',
                    'd4' => 'بستگان تا درجه ۴',
                    'd3' => 'بستگان تا درجه ۳',
                    'd2' => 'بستگان تا درجه ۲',
                    'd1' => 'فقط بستگان درجه ۱',
                    'self' => 'فقط خودم (و مدیر سایت)',
                ],
                'texts' => array_map(fn ($t) => ['label' => $t['label'], 'max' => $t['max']], config('pedigree.profile.texts', [])),
                'resume_types' => config('pedigree.profile.resume_types'),
                'max_attributes' => (int) config('pedigree.profile.max_attributes', 40),
                'countries' => Countries::CODES,
            ],
            'map' => [
                'enabled' => (bool) config('pedigree.map.enabled', true),
                'tiles' => config('pedigree.map.tiles'),
                'attribution' => config('pedigree.map.attribution'),
                'max_zoom' => (int) config('pedigree.map.max_zoom', 19),
                'center' => config('pedigree.map.center'),
                'zoom' => (int) config('pedigree.map.zoom', 5),
            ],
            'comments_enabled' => (bool) config('pedigree.comments.enabled', true),
            'comment_max' => (int) config('pedigree.comments.max_length', 3000),
            'ratings' => [
                'enabled' => (bool) config('pedigree.ratings.enabled', true),
                'traits' => config('pedigree.ratings.traits'),
            ],
            'history_public' => (bool) config('pedigree.permissions.history_public', true),
            'assistant' => [
                'enabled' => app(AssistantService::class)->configured(),
                'restore' => app(PhotoRestorer::class)->enabled(),
                'family' => FamilyFacts::enabled(),
                'voice' => app(VoiceAi::class)->sttProvider() !== null || app(VoiceAi::class)->liveProvider() !== null,
            ],
            'group' => ['enabled' => (bool) config('pedigree.group.enabled', true), 'name' => (string) config('pedigree.group.name')],
            'messaging' => ['enabled' => (bool) config('pedigree.messaging.enabled', true)],
            'announcement' => config('pedigree.announcement.enabled') && trim((string) config('pedigree.announcement.text')) !== ''
                ? ['text' => (string) config('pedigree.announcement.text'), 'level' => (string) config('pedigree.announcement.level', 'info')]
                : null,
        ]);
    }
}
