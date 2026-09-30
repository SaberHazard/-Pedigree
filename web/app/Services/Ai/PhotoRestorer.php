<?php

namespace App\Services\Ai;

use App\Exceptions\DomainException;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaFormats;
use App\Services\Media\MediaService;
use App\Services\Social\SafeHttp;
use App\Support\Outbound;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * زنده کردن عکس‌های قدیمی با هوش مصنوعی (مدل تصویری Gemini):
 * بازسازی (رفع خط و خش، لک، پارگی و تاری) و/یا رنگی کردن عکس سیاه‌وسفید.
 *
 *  - نسخه تازه یک عکس جدید در همان پروفایل است (اصلی دست نمی‌خورد) و مثل هر آپلودی تأیید می‌شود
 *  - در توضیح عکس نوشته می‌شود که با هوش مصنوعی ساخته شده است
 *  - فقط عکس برای Google فرستاده می‌شود (کاربر پیش از ارسال تأیید می‌کند)؛ سقف روزانه هر عضو و کل سایت
 */
class PhotoRestorer
{
    public const MODES = [
        'restore' => 'بازسازی (رفع خط و خش، لک و تاری)',
        'colorize' => 'رنگی کردن عکس سیاه‌وسفید',
        'both' => 'بازسازی و رنگی کردن',
    ];

    private const PROMPTS = [
        'restore' => 'Restore this old family photograph: remove scratches, dust, stains, creases, tears and noise; fix fading and blur; improve sharpness, contrast and exposure naturally. Keep every person\'s face, identity, age, expression, pose, clothing and the whole composition exactly the same. Do not add, remove or change any person or object. If it is black-and-white keep it black-and-white. Return only the restored photo.',
        'colorize' => 'Colorize this old black-and-white family photograph with natural, realistic and historically plausible colors (skin tones, hair, clothes, sky, plants, buildings). Keep every face, identity, expression and detail exactly the same; do not add, remove or change anything. Return only the colorized photo.',
        'both' => 'Restore and colorize this old family photograph: remove scratches, dust, stains, creases, tears and noise, fix fading and blur, then add natural, realistic and historically plausible colors. Keep every person\'s face, identity, age, expression, pose, clothing and the whole composition exactly the same; do not add, remove or change any person or object. Return only the final photo.',
    ];

    private const HOST = 'generativelanguage.googleapis.com';

    public function __construct(
        private readonly SafeHttp $http,
        private readonly MediaService $media,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('pedigree.ai.enabled')
            && (bool) config('pedigree.ai.image.enabled')
            && ! empty(config('pedigree.ai.providers.gemini.api_key'))
            && preg_match('/^[A-Za-z0-9._-]{1,80}$/', (string) config('pedigree.ai.image.model')) === 1;
    }

    public function remaining(User $user): int
    {
        return RateLimiter::remaining('ai-img-u:'.$user->id, max(1, (int) config('pedigree.ai.image.daily_per_user', 5)));
    }

    /**
     * ساخت نسخه بازسازی‌شده/رنگی یک عکس
     *
     * @throws DomainException
     */
    public function restore(User $user, Media $original, string $mode): Media
    {
        if (! $this->enabled()) {
            throw new DomainException('بازسازی عکس با هوش مصنوعی فعال نیست؛ مدیر سایت باید کلید Gemini را وارد کند.', 503, 'ai_off');
        }
        if (! isset(self::PROMPTS[$mode])) {
            throw new DomainException('نوع بازسازی معتبر نیست.');
        }
        if (! $original->isImage() || ! $original->isApproved()) {
            throw new DomainException('فقط عکس‌های تأییدشده را می‌شود بازسازی کرد.');
        }
        $disk = Storage::disk($original->disk);
        if (! $disk->exists($original->path) || $disk->size($original->path) > 8 * 1024 * 1024) {
            throw new DomainException('فایل این عکس در دسترس نیست.');
        }

        $userKey = 'ai-img-u:'.$user->id;
        $globalKey = 'ai-img-g:'.now('Asia/Tehran')->format('Ymd');
        if (RateLimiter::tooManyAttempts($userKey, max(1, (int) config('pedigree.ai.image.daily_per_user', 5)))) {
            throw new DomainException('سهمیه امروز شما برای بازسازی عکس تمام شده است؛ فردا دوباره امتحان کنید.', 429, 'ai_quota');
        }
        if (RateLimiter::tooManyAttempts($globalKey, max(1, (int) config('pedigree.ai.image.global_daily', 60)))) {
            throw new DomainException('سهمیه امروز سایت برای بازسازی عکس تمام شده است.', 429, 'ai_quota');
        }
        RateLimiter::hit($userKey, 86400);
        RateLimiter::hit($globalKey, 86400);

        $binary = $this->generate($disk->get($original->path), $original->mime ?: 'image/jpeg', self::PROMPTS[$mode]);

        $temp = tempnam(sys_get_temp_dir(), 'ai-img');
        try {
            file_put_contents($temp, $binary);
            if (MediaFormats::detectImage($temp) === null) {
                throw new DomainException('سرویس هوش مصنوعی عکس معتبری برنگرداند؛ دوباره امتحان کنید.', 502, 'ai_empty');
            }
            $label = ['restore' => 'بازسازی‌شده', 'colorize' => 'رنگی‌شده', 'both' => 'بازسازی و رنگی‌شده'][$mode];
            $caption = mb_substr('✨ '.$label.($original->caption ? ': '.$original->caption : ''), 0, 300);

            return $this->media->store($original->person, new UploadedFile($temp, 'restored.png', null, null, true), $user, [
                'caption' => $caption,
                'description' => 'این نسخه با هوش مصنوعی از روی عکس اصلی ساخته شده است و ممکن است جزئیاتش با واقعیت تفاوت داشته باشد.',
                'taken_at' => $original->taken_at,
                'category' => $original->category === Media::CATEGORY_MEMORY ? Media::CATEGORY_MEMORY : Media::CATEGORY_GALLERY,
            ]);
        } finally {
            @unlink($temp);
        }
    }

    /** فراخوانی مدل تصویری Gemini و گرفتن عکس خروجی */
    private function generate(string $image, string $mime, string $prompt): string
    {
        $model = (string) config('pedigree.ai.image.model');
        $response = $this->http->postJson(
            'https://'.self::HOST.'/v1beta/models/'.$model.':generateContent',
            [self::HOST],
            [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($image)]],
                    ],
                ]],
                'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
            ],
            40 * 1024 * 1024,
            ['x-goog-api-key' => (string) config('pedigree.ai.providers.gemini.api_key'), 'Accept' => 'application/json'],
            Outbound::foreign(config('pedigree.ai.proxy') ?: config('pedigree.social.proxy')),
            (int) config('pedigree.ai.image.timeout', 120),
        );
        $json = json_decode($response['body'], true);
        if ($response['status'] >= 400 || ! is_array($json)) {
            Log::warning('AI image error', ['status' => $response['status'], 'detail' => is_array($json) ? mb_substr((string) ($json['error']['message'] ?? ''), 0, 300) : null]);
            throw match (true) {
                $response['status'] === 429 => new DomainException('سرویس بازسازی عکس فعلاً شلوغ است یا سهمیه آن تمام شده؛ کمی بعد امتحان کنید.', 503, 'ai_busy'),
                $response['status'] === 401, $response['status'] === 403 => new DomainException('سرویس هوش مصنوعی درخواست را نپذیرفت (کلید یا محدودیت منطقه‌ای؛ اگر سرور در ایران است پراکسی لازم است).', 502, 'ai_auth'),
                default => new DomainException('بازسازی عکس ممکن نشد؛ کمی بعد دوباره امتحان کنید.', 502, 'ai_down'),
            };
        }
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            $data = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
            if (is_string($data) && $data !== '') {
                $binary = base64_decode($data, true);
                if ($binary !== false && $binary !== '') {
                    return $binary;
                }
            }
        }

        throw new DomainException('سرویس هوش مصنوعی عکسی برنگرداند (شاید عکس را نپذیرفت)؛ عکس دیگری را امتحان کنید.', 422, 'ai_blocked');
    }
}
