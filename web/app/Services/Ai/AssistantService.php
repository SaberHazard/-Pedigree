<?php

namespace App\Services\Ai;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Social\SafeHttp;
use App\Support\Jalali;
use App\Support\PersianText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * دستیار هوش مصنوعی سایت.
 *
 *  - سرویس‌ها: Google Gemini (سهمیه رایگان)، OpenRouter (مدل‌های رایگان)، Groq (سهمیه رایگان)،
 *    ChatGPT (OpenAI)، Claude (Anthropic) و هر سرویس سازگار با OpenAI (مثلاً درگاه‌های داخلی)
 *  - گفتگو در سرور ذخیره نمی‌شود و هیچ اطلاعاتی از شجره‌نامه برای سرویس فرستاده نمی‌شود؛
 *    فقط همان متنی که کاربر در همین گفتگو نوشته است
 *  - درخواست‌ها از مسیر امن SafeHttp (فقط https، دامنه مجاز، بدون IP داخلی) و در صورت نیاز با پراکسی
 *  - سقف روزانه برای هر عضو و کل سایت
 */
class AssistantService
{
    public const PROVIDERS = [
        'gemini' => ['label' => 'Google Gemini', 'host' => 'generativelanguage.googleapis.com', 'free' => true],
        'openrouter' => ['label' => 'OpenRouter', 'base' => 'https://openrouter.ai/api/v1', 'free' => true],
        'groq' => ['label' => 'Groq', 'base' => 'https://api.groq.com/openai/v1', 'free' => true],
        'openai' => ['label' => 'ChatGPT (OpenAI)', 'base' => 'https://api.openai.com/v1', 'free' => false],
        'anthropic' => ['label' => 'Claude (Anthropic)', 'host' => 'api.anthropic.com', 'free' => false],
        'custom' => ['label' => 'سرویس سازگار با OpenAI', 'free' => false],
    ];

    /** بیشترین طول هر پیام کاربر */
    public const MAX_CHARS = 4000;

    /** بیشترین تعداد پیام‌های گفتگو که برای سرویس فرستاده می‌شود */
    public const MAX_TURNS = 20;

    /** بیشترین مجموع نویسه‌های گفتگوی فرستاده‌شده */
    public const MAX_TOTAL = 16000;

    private const MAX_RESPONSE_BYTES = 512 * 1024;

    public function __construct(private readonly SafeHttp $http) {}

    public function provider(): string
    {
        $provider = (string) config('pedigree.ai.provider');

        return isset(self::PROVIDERS[$provider]) ? $provider : 'gemini';
    }

    public function label(): string
    {
        return self::PROVIDERS[$this->provider()]['label'];
    }

    /** دستیار روشن است و کلید و مدل سرویس انتخاب‌شده تنظیم شده؟ */
    public function configured(): bool
    {
        if (! config('pedigree.ai.enabled')) {
            return false;
        }
        $provider = $this->provider();
        $conf = (array) config("pedigree.ai.providers.{$provider}");

        return ! empty($conf['api_key']) && ! empty($conf['model'])
            && ($provider !== 'custom' || ! empty($conf['base_url']));
    }

    /** تعداد پیام باقی‌مانده امروز برای کاربر */
    public function remaining(User $user): int
    {
        return RateLimiter::remaining($this->userKey($user), $this->perUser());
    }

    /**
     * یک نوبت گفتگو
     *
     * @param  array<int, array{role:string, content:string}>  $messages  کل گفتگو تا اینجا (آخری از کاربر)
     *
     * @throws DomainException
     */
    public function chat(User $user, array $messages): string
    {
        if (! $this->configured()) {
            throw new DomainException('دستیار هوش مصنوعی هنوز راه‌اندازی نشده است؛ مدیر سایت باید کلید یکی از سرویس‌ها را در «تنظیمات و اتصال‌ها» وارد کند.', 503, 'ai_off');
        }
        $messages = $this->prepare($messages);

        // سقف روزانه هر عضو و کل سایت (پیش از تماس با سرویس)
        $userKey = $this->userKey($user);
        $globalKey = 'ai-g:'.now('Asia/Tehran')->format('Ymd');
        if (RateLimiter::tooManyAttempts($userKey, $this->perUser())) {
            throw new DomainException('سهمیه امروز شما برای گفتگو با دستیار تمام شده است؛ فردا دوباره امتحان کنید.', 429, 'ai_quota');
        }
        if (RateLimiter::tooManyAttempts($globalKey, max(1, (int) config('pedigree.ai.global_daily', 2000)))) {
            throw new DomainException('سهمیه امروز سایت برای دستیار هوش مصنوعی تمام شده است؛ فردا دوباره امتحان کنید.', 429, 'ai_quota');
        }
        RateLimiter::hit($userKey, 86400);
        RateLimiter::hit($globalKey, 86400);

        return $this->complete($this->systemPrompt(), $messages, (int) config('pedigree.ai.max_output_tokens', 1500));
    }

    /** آزمایش اتصال از پنل مدیریت (بدون مصرف سهمیه اعضا) */
    public function ping(): string
    {
        if (! $this->configured()) {
            throw new DomainException('ابتدا دستیار را روشن کنید و کلید API و نام مدل سرویس انتخاب‌شده را وارد و ذخیره کنید.');
        }
        $reply = $this->complete('Reply with one short friendly Persian sentence.', [['role' => 'user', 'content' => 'سلام! آماده‌ای؟']], 60);

        return 'اتصال به '.$this->label().' برقرار است. پاسخ: «'.mb_substr($reply, 0, 120).'»';
    }

    // ------------------------------------------------------------------ آماده‌سازی گفتگو

    /**
     * یکدست‌سازی گفتگو: فقط نقش user/assistant، حذف نویسه‌های کنترلی، ادغام پیام‌های پشت سر هم هم‌نقش،
     * شروع و پایان با پیام کاربر، و حذف قدیمی‌ترها تا زیر سقف طول.
     *
     * @return array<int, array{role:string, content:string}>
     */
    public function prepare(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? null;
            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($m['content'] ?? null)) {
                continue;
            }
            $content = self::clean($m['content']);
            if ($content === '') {
                continue;
            }
            if ($out && end($out)['role'] === $role) {
                $out[count($out) - 1]['content'] .= "\n\n".$content;
            } else {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }
        if (! $out || end($out)['role'] !== 'user') {
            throw new DomainException('پیامی برای فرستادن نیست.');
        }
        if (mb_strlen(end($out)['content']) > self::MAX_CHARS) {
            throw new DomainException('پیام حداکثر '.PersianText::toPersianDigits((string) self::MAX_CHARS).' نویسه باشد.');
        }

        $out = array_slice($out, -self::MAX_TURNS);
        while (count($out) > 1 && (array_sum(array_map(fn ($m) => mb_strlen($m['content']), $out)) > self::MAX_TOTAL || $out[0]['role'] !== 'user')) {
            array_shift($out);
        }

        return array_values($out);
    }

    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';

        return trim($text);
    }

    private function systemPrompt(): string
    {
        [$y, $m, $d] = Jalali::today();
        $site = (string) config('pedigree.site_name');

        return <<<PROMPT
        تو «دستیار هوشمند» سایت شجره‌نامه خانوادگی «{$site}» هستی و با اعضای یک خاندان ایرانی گفتگو می‌کنی.
        - به فارسی روان، گرم و مؤدبانه پاسخ بده، مگر اینکه کاربر زبان دیگری بخواهد. پاسخ‌ها کوتاه و کاربردی باشند مگر کاربر متن بلند بخواهد.
        - در این کارها به‌خوبی کمک کن: نوشتن و ویرایش زندگی‌نامه و خاطره، متن تبریک تولد و مناسبت‌ها، متن تسلیت و یادبود، ایده برای دورهمی خانوادگی، معنی و ریشه نام‌ها و نام‌های خانوادگی، روش پژوهش در تاریخچه خانواده، تاریخ و فرهنگ ایران، و پرسش‌های روزمره.
        - به اطلاعات شجره‌نامه و اعضای خانواده دسترسی نداری؛ فقط از چیزی که کاربر در همین گفتگو نوشته خبر داری. اگر درباره اعضا پرسید، همین را بگو و از خودت اطلاعات نساز.
        - هرگز اطلاعات حساس مثل کد ملی، رمز، کد تأیید یا شماره کارت نخواه.
        - در موضوعات پزشکی، حقوقی و مالی اطلاعات عمومی بده و توصیه کن با متخصص مشورت شود.
        - امروز {$y}/{$m}/{$d} خورشیدی است.
        PROMPT;
    }

    // ------------------------------------------------------------------ تماس با سرویس

    /** @param array<int, array{role:string, content:string}> $messages */
    private function complete(string $system, array $messages, int $maxTokens): string
    {
        $provider = $this->provider();
        $conf = (array) config("pedigree.ai.providers.{$provider}");
        $key = (string) $conf['api_key'];
        $model = trim((string) $conf['model']);
        if (! preg_match('#^[A-Za-z0-9._:/@-]{1,120}$#', $model)) {
            throw new DomainException('نام مدل هوش مصنوعی معتبر نیست.', 503, 'ai_off');
        }

        [$url, $hosts, $headers, $body] = match ($provider) {
            'gemini' => $this->geminiRequest($key, $model, $system, $messages, $maxTokens),
            'anthropic' => $this->anthropicRequest($key, $model, $system, $messages, $maxTokens),
            default => $this->openAiRequest($provider, $conf, $key, $model, $system, $messages, $maxTokens),
        };

        $response = $this->http->postJson(
            $url,
            $hosts,
            $body,
            self::MAX_RESPONSE_BYTES,
            $headers + ['Accept' => 'application/json'],
            config('pedigree.ai.proxy') ?: null,
            (int) config('pedigree.ai.timeout', 60),
        );
        $json = json_decode($response['body'], true);

        if ($response['status'] >= 400 || ! is_array($json)) {
            $this->fail($response['status'], is_array($json) ? $json : null);
        }

        $text = match ($provider) {
            'gemini' => $this->geminiText($json),
            'anthropic' => collect($json['content'] ?? [])->where('type', 'text')->pluck('text')->implode(''),
            default => (string) ($json['choices'][0]['message']['content'] ?? ''),
        };
        $text = trim(self::clean($text));
        if ($text === '') {
            throw new DomainException('سرویس هوش مصنوعی پاسخی نداد؛ پرسش را کمی تغییر دهید و دوباره امتحان کنید.', 502, 'ai_empty');
        }

        return mb_substr($text, 0, 20000);
    }

    /** @return array{0:string, 1:string[], 2:array, 3:array} */
    private function openAiRequest(string $provider, array $conf, string $key, string $model, string $system, array $messages, int $maxTokens): array
    {
        $base = $provider === 'custom' ? rtrim((string) $conf['base_url'], '/') : self::PROVIDERS[$provider]['base'];
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if (! str_starts_with($base, 'https://') || $host === '') {
            throw new DomainException('آدرس سرویس هوش مصنوعی باید https باشد.', 503, 'ai_off');
        }
        $headers = ['Authorization' => 'Bearer '.$key];
        if ($provider === 'openrouter') {
            $headers += ['HTTP-Referer' => (string) config('app.url'), 'X-Title' => 'Pedigree family tree'];
        }

        return [
            $base.'/chat/completions',
            [$host],
            $headers,
            [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ...$messages],
                'max_tokens' => $maxTokens,
                'temperature' => 0.7,
            ],
        ];
    }

    /** @return array{0:string, 1:string[], 2:array, 3:array} */
    private function geminiRequest(string $key, string $model, string $system, array $messages, int $maxTokens): array
    {
        $model = preg_replace('#^models/#', '', $model);
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $model)) {
            throw new DomainException('نام مدل Gemini معتبر نیست.', 503, 'ai_off');
        }

        return [
            'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent',
            [self::PROVIDERS['gemini']['host']],
            ['x-goog-api-key' => $key],
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => array_map(fn ($m) => [
                    'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content']]],
                ], $messages),
                'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.7],
            ],
        ];
    }

    private function geminiText(array $json): string
    {
        if (isset($json['promptFeedback']['blockReason'])) {
            throw new DomainException('سرویس هوش مصنوعی به این پرسش پاسخ نمی‌دهد؛ آن را طور دیگری بپرسید.', 422, 'ai_blocked');
        }

        return collect($json['candidates'][0]['content']['parts'] ?? [])->pluck('text')->filter(fn ($t) => is_string($t))->implode('');
    }

    /** @return array{0:string, 1:string[], 2:array, 3:array} */
    private function anthropicRequest(string $key, string $model, string $system, array $messages, int $maxTokens): array
    {
        return [
            'https://api.anthropic.com/v1/messages',
            [self::PROVIDERS['anthropic']['host']],
            ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => $messages,
            ],
        ];
    }

    /** @throws DomainException */
    private function fail(int $status, ?array $json): never
    {
        // جزئیات فقط در لاگ سرور (بدون کلید)؛ به کاربر پیام کلی
        $detail = $json['error']['message'] ?? $json['error'] ?? $json['message'] ?? null;
        Log::warning('AI provider error', [
            'provider' => $this->provider(),
            'status' => $status,
            'detail' => is_string($detail) ? mb_substr($detail, 0, 300) : null,
        ]);

        throw match (true) {
            $status === 401, $status === 403 => new DomainException('سرویس هوش مصنوعی درخواست را نپذیرفت (کلید نامعتبر یا محدودیت منطقه‌ای). اگر سرور در ایران است، پراکسی لازم است.', 502, 'ai_auth'),
            $status === 429 => new DomainException('سرویس هوش مصنوعی فعلاً شلوغ است یا سهمیه رایگان آن تمام شده؛ کمی بعد دوباره امتحان کنید.', 503, 'ai_busy'),
            $status === 404 => new DomainException('مدل انتخاب‌شده در سرویس هوش مصنوعی پیدا نشد؛ مدیر سایت نام مدل را بررسی کند.', 502, 'ai_model'),
            $status >= 400 && $status < 500 => new DomainException('سرویس هوش مصنوعی درخواست را نپذیرفت؛ پیام را کوتاه‌تر کنید یا گفتگوی تازه‌ای شروع کنید.', 502, 'ai_rejected'),
            default => new DomainException('سرویس هوش مصنوعی در دسترس نیست؛ کمی بعد دوباره امتحان کنید.', 502, 'ai_down'),
        };
    }

    private function userKey(User $user): string
    {
        return 'ai-u:'.$user->id;
    }

    private function perUser(): int
    {
        return max(1, (int) config('pedigree.ai.daily_per_user', 40));
    }
}
