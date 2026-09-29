<?php

namespace App\Services\Ai;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Media\VoiceService;
use App\Services\Social\SafeHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * صدا و هوش مصنوعی:
 *  - گفتار به متن (پیام صوتی فارسی به دستیار و بازی‌ها): Whisper در Groq (رایگان) یا OpenAI، یا خود Gemini
 *  - متن به گفتار (خواندن پاسخ دستیار): Gemini یا OpenAI؛ یا صدای خود گوشی/مرورگر (بدون هزینه)
 *  - گفتگوی صوتی زنده: سرور فقط یک «کلید یک‌بارمصرف کوتاه‌عمر» از Gemini Live یا OpenAI Realtime می‌گیرد و
 *    مرورگر مستقیم به سرویس وصل می‌شود؛ صدا از سرور سایت عبور نمی‌کند و کلید اصلی هرگز به مرورگر نمی‌رسد.
 */
class VoiceAi
{
    private const HOSTS = [
        'gemini' => 'generativelanguage.googleapis.com',
        'openai' => 'api.openai.com',
        'groq' => 'api.groq.com',
    ];

    public function __construct(
        private readonly SafeHttp $http,
        private readonly VoiceService $voices,
        private readonly AssistantService $assistant,
    ) {}

    // ------------------------------------------------------------------ وضعیت

    private static function key(string $provider): string
    {
        return (string) config("pedigree.ai.providers.{$provider}.api_key");
    }

    /** سرویس گفتار به متن (null = خاموش یا کلید ندارد) */
    public function sttProvider(): ?string
    {
        if (! config('pedigree.ai.enabled')) {
            return null;
        }
        $choice = (string) config('pedigree.ai.voice.stt', 'auto');
        $order = $choice === 'auto' ? ['groq', 'openai', 'gemini'] : [$choice];
        foreach ($order as $p) {
            if (isset(self::HOSTS[$p]) && self::key($p) !== '') {
                return $p;
            }
        }

        return null;
    }

    /** سرویس متن به گفتار: gemini | openai | browser (صدای خود گوشی) | null (خاموش) */
    public function ttsProvider(): ?string
    {
        if (! config('pedigree.ai.enabled')) {
            return null;
        }
        $choice = (string) config('pedigree.ai.voice.tts', 'browser');

        return match (true) {
            $choice === 'off' => null,
            in_array($choice, ['gemini', 'openai'], true) && self::key($choice) !== '' => $choice,
            default => 'browser',
        };
    }

    /** سرویس گفتگوی صوتی زنده (null = خاموش) */
    public function liveProvider(): ?string
    {
        $p = (string) config('pedigree.ai.live.provider', 'off');

        return config('pedigree.ai.enabled') && in_array($p, ['gemini', 'openai'], true) && self::key($p) !== '' ? $p : null;
    }

    public function summary(User $user): array
    {
        $live = $this->liveProvider();

        return [
            'stt' => $this->sttProvider() !== null,
            'tts' => $this->ttsProvider(),
            'live' => $live,
            'live_minutes' => $live ? $this->liveMinutes() : null,
            'live_left' => $live ? RateLimiter::remaining('ai-live-u:'.$user->id, max(1, (int) config('pedigree.ai.live.daily_per_user', 5))) : 0,
            'max_seconds' => (int) config('pedigree.ai.voice.max_seconds', 120),
        ];
    }

    private function liveMinutes(): int
    {
        return max(1, min(30, (int) config('pedigree.ai.live.max_minutes', 10)));
    }

    // ------------------------------------------------------------------ گفتار به متن

    /** @throws DomainException */
    public function transcribe(User $user, UploadedFile $file): string
    {
        $provider = $this->sttProvider();
        if ($provider === null) {
            throw new DomainException('تبدیل صدا به متن فعال نیست؛ مدیر سایت باید کلید Groq، OpenAI یا Gemini را وارد کند.', 503, 'ai_voice_off');
        }
        $path = (string) $file->getRealPath();
        if ($file->getSize() > 6 * 1024 * 1024 || VoiceService::detect($path) === null) {
            throw new DomainException('فایل صوتی معتبر نیست.');
        }
        $seconds = $this->voices->duration($path);
        $max = (int) config('pedigree.ai.voice.max_seconds', 120);
        if ($seconds === null || $seconds < 0.5) {
            throw new DomainException('صدایی ضبط نشد؛ دوباره امتحان کنید.');
        }
        if ($seconds > $max + 1) {
            throw new DomainException('پیام صوتی به دستیار حداکثر '.intdiv($max, 60).' دقیقه باشد.');
        }
        $this->assistant->consumeQuota($user);

        $audio = $this->toOgg($path) ?? (string) file_get_contents($path);
        $mime = str_starts_with($audio, 'OggS') ? 'audio/ogg' : (string) ($file->getMimeType() ?: 'audio/webm');
        $proxy = config('pedigree.ai.proxy') ?: null;

        if ($provider === 'gemini') {
            $model = (string) config('pedigree.ai.providers.gemini.model', 'gemini-flash-latest');
            $res = $this->http->postJson('https://'.self::HOSTS['gemini'].'/v1beta/models/'.rawurlencode($model).':generateContent', [self::HOSTS['gemini']], [
                'contents' => [['role' => 'user', 'parts' => [
                    ['text' => 'این پیام صوتی (معمولاً فارسی) را کلمه‌به‌کلمه و دقیق به متن بنویس. فقط متن گفته‌شده را برگردان، بدون توضیح یا ترجمه.'],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($audio)]],
                ]]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 2048],
            ], 2 * 1024 * 1024, ['x-goog-api-key' => self::key('gemini')], $proxy, 90);
            $json = json_decode($res['body'], true);
            $this->check('gemini', $res['status'], $json);
            $text = '';
            foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
                $text .= (string) ($part['text'] ?? '');
            }
        } else {
            $base = $provider === 'groq' ? 'https://api.groq.com/openai/v1' : 'https://api.openai.com/v1';
            $model = (string) config("pedigree.ai.voice.stt_models.{$provider}", $provider === 'groq' ? 'whisper-large-v3-turbo' : 'gpt-4o-mini-transcribe');
            $res = $this->http->postMultipart($base.'/audio/transcriptions', [self::HOSTS[$provider]],
                ['model' => $model, 'language' => 'fa', 'response_format' => 'json', 'temperature' => '0'],
                ['file' => [$audio, $mime === 'audio/ogg' ? 'voice.ogg' : 'voice.webm', $mime]],
                1024 * 1024, ['Authorization' => 'Bearer '.self::key($provider), 'Accept' => 'application/json'], $proxy, 90);
            $json = json_decode($res['body'], true);
            $this->check($provider, $res['status'], $json);
            $text = (string) ($json['text'] ?? '');
        }

        $text = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '');
        if ($text === '') {
            throw new DomainException('صدایی تشخیص داده نشد؛ کمی بلندتر و واضح‌تر دوباره امتحان کنید.', 422, 'ai_empty');
        }

        return mb_substr($text, 0, AssistantService::MAX_CHARS);
    }

    // ------------------------------------------------------------------ متن به گفتار

    /**
     * @return array{0:string, 1:string} [فایل صوتی، نوع]
     *
     * @throws DomainException
     */
    public function speak(User $user, string $text): array
    {
        $provider = $this->ttsProvider();
        if (! in_array($provider, ['gemini', 'openai'], true)) {
            throw new DomainException('خواندن پاسخ با صدای سرور فعال نیست؛ صدای خود گوشی استفاده می‌شود.', 422, 'tts_browser');
        }
        // متن تمیز برای خواندن (بدون نشانه‌های قالب‌بندی)
        $text = trim(preg_replace(['/[*_#`>|~]+/u', '/\s+/u'], ['', ' '], $text) ?? '');
        $text = mb_substr($text, 0, 1200);
        if ($text === '') {
            throw new DomainException('متنی برای خواندن نیست.');
        }
        foreach ([['ai-tts-u:'.$user->id, (int) config('pedigree.ai.voice.tts_daily_per_user', 60)], ['ai-tts-g:'.now('Asia/Tehran')->format('Ymd'), (int) config('pedigree.ai.voice.tts_global_daily', 1500)]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, max(1, $max))) {
                throw new DomainException('سهمیه امروز خواندن پاسخ‌ها تمام شده است.', 429, 'ai_quota');
            }
        }
        RateLimiter::hit('ai-tts-u:'.$user->id, 86400);
        RateLimiter::hit('ai-tts-g:'.now('Asia/Tehran')->format('Ymd'), 86400);
        $proxy = config('pedigree.ai.proxy') ?: null;
        $voice = (string) config("pedigree.ai.voice.tts_voice.{$provider}", $provider === 'gemini' ? 'Kore' : 'alloy');
        if (! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $voice)) {
            $voice = $provider === 'gemini' ? 'Kore' : 'alloy';
        }

        if ($provider === 'openai') {
            $res = $this->http->postJson('https://api.openai.com/v1/audio/speech', [self::HOSTS['openai']], [
                'model' => (string) config('pedigree.ai.voice.tts_models.openai', 'gpt-4o-mini-tts'),
                'voice' => $voice,
                'input' => $text,
                'instructions' => 'Speak in natural, warm Persian (Farsi) with correct Persian pronunciation.',
                'response_format' => 'aac',
            ], 6 * 1024 * 1024, ['Authorization' => 'Bearer '.self::key('openai')], $proxy, 90);
            if ($res['status'] >= 400) {
                $this->check('openai', $res['status'], json_decode($res['body'], true));
            }

            return [$res['body'], 'audio/aac'];
        }

        $model = (string) config('pedigree.ai.voice.tts_models.gemini', 'gemini-2.5-flash-preview-tts');
        $res = $this->http->postJson('https://'.self::HOSTS['gemini'].'/v1beta/models/'.rawurlencode($model).':generateContent', [self::HOSTS['gemini']], [
            'contents' => [['parts' => [['text' => 'با لحنی گرم و طبیعی به فارسی بخوان: '.$text]]]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]],
            ],
        ], 20 * 1024 * 1024, ['x-goog-api-key' => self::key('gemini')], $proxy, 90);
        $json = json_decode($res['body'], true);
        $this->check('gemini', $res['status'], $json);
        $data = $json['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
        $pcm = is_string($data) ? base64_decode($data, true) : false;
        if (! $pcm) {
            throw new DomainException('سرویس صدایی برنگرداند؛ دوباره امتحان کنید.', 502, 'ai_empty');
        }
        $wav = self::wav($pcm, 24000);

        return $this->toAac($wav) ?? [$wav, 'audio/wav'];
    }

    /** سربرگ WAV برای PCM شانزده‌بیتی تک‌کاناله */
    public static function wav(string $pcm, int $rate): string
    {
        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    // ------------------------------------------------------------------ گفتگوی زنده

    /**
     * کلید یک‌بارمصرف و کوتاه‌عمر برای اتصال مستقیم مرورگر به سرویس صوتی
     *
     * @throws DomainException
     */
    public function liveSession(User $user, string $mode): array
    {
        $provider = $this->liveProvider();
        if ($provider === null) {
            throw new DomainException('گفتگوی صوتی زنده با هوش مصنوعی فعال نیست.', 503, 'ai_live_off');
        }
        $mode = isset(AssistantService::MODES[$mode]) ? $mode : 'chat';
        $userKey = 'ai-live-u:'.$user->id;
        $globalKey = 'ai-live-g:'.now('Asia/Tehran')->format('Ymd');
        if (RateLimiter::tooManyAttempts($userKey, max(1, (int) config('pedigree.ai.live.daily_per_user', 5)))) {
            throw new DomainException('سهمیه امروز شما برای گفتگوی صوتی تمام شده است؛ فردا دوباره امتحان کنید.', 429, 'ai_quota');
        }
        if (RateLimiter::tooManyAttempts($globalKey, max(1, (int) config('pedigree.ai.live.global_daily', 100)))) {
            throw new DomainException('سهمیه امروز سایت برای گفتگوی صوتی تمام شده است.', 429, 'ai_quota');
        }
        $instructions = $this->assistant->instructionsFor($user, $mode)
            ."\n\nاین یک گفتگوی صوتی زنده است: کوتاه، گرم و گفتاری به فارسی صحبت کن؛ فهرست و نشانه‌های نوشتاری نگو. اگر کاربر حرفت را قطع کرد، کوتاه کن و گوش بده.";
        $seconds = $this->liveMinutes() * 60;
        $proxy = config('pedigree.ai.proxy') ?: null;

        if ($provider === 'gemini') {
            $model = (string) config('pedigree.ai.live.gemini_model', 'gemini-2.5-flash-native-audio-preview-09-2025');
            $voice = (string) config('pedigree.ai.live.voice.gemini', 'Kore');
            $setup = [
                'model' => 'models/'.$model,
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]],
                ],
                'systemInstruction' => ['parts' => [['text' => $instructions]]],
                'inputAudioTranscription' => (object) [],
                'outputAudioTranscription' => (object) [],
            ];
            $res = $this->http->postJson('https://'.self::HOSTS['gemini'].'/v1alpha/auth_tokens', [self::HOSTS['gemini']], [
                'uses' => 1,
                'expireTime' => now()->addSeconds($seconds)->utc()->format('Y-m-d\TH:i:s\Z'),
                'newSessionExpireTime' => now()->addSeconds(90)->utc()->format('Y-m-d\TH:i:s\Z'),
                'bidiGenerateContentSetup' => $setup,
            ], 256 * 1024, ['x-goog-api-key' => self::key('gemini')], $proxy, 30);
            $json = json_decode($res['body'], true);
            $this->check('gemini', $res['status'], $json);
            $token = (string) ($json['name'] ?? '');
            if ($token === '') {
                throw new DomainException('کلید گفتگوی صوتی ساخته نشد.', 502, 'ai_down');
            }
            $session = [
                'provider' => 'gemini',
                'token' => $token,
                'url' => 'wss://'.self::HOSTS['gemini'].'/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContentConstrained',
                'setup' => ['model' => 'models/'.$model, 'generationConfig' => ['responseModalities' => ['AUDIO']], 'inputAudioTranscription' => (object) [], 'outputAudioTranscription' => (object) []],
            ];
        } else {
            $model = (string) config('pedigree.ai.live.openai_model', 'gpt-realtime');
            $voice = (string) config('pedigree.ai.live.voice.openai', 'marin');
            $res = $this->http->postJson('https://api.openai.com/v1/realtime/client_secrets', [self::HOSTS['openai']], [
                'expires_after' => ['anchor' => 'created_at', 'seconds' => 60],
                'session' => [
                    'type' => 'realtime',
                    'model' => $model,
                    'instructions' => $instructions,
                    'max_output_tokens' => 1024,
                    'audio' => [
                        'input' => ['transcription' => ['model' => 'gpt-4o-mini-transcribe', 'language' => 'fa']],
                        'output' => ['voice' => $voice],
                    ],
                ],
            ], 256 * 1024, ['Authorization' => 'Bearer '.self::key('openai')], $proxy, 30);
            $json = json_decode($res['body'], true);
            $this->check('openai', $res['status'], $json);
            $token = (string) ($json['value'] ?? $json['client_secret']['value'] ?? '');
            if ($token === '') {
                throw new DomainException('کلید گفتگوی صوتی ساخته نشد.', 502, 'ai_down');
            }
            $session = ['provider' => 'openai', 'token' => $token, 'url' => 'https://api.openai.com/v1/realtime/calls?model='.rawurlencode($model)];
        }
        RateLimiter::hit($userKey, 86400);
        RateLimiter::hit($globalKey, 86400);

        return $session + ['max_seconds' => $seconds, 'left' => RateLimiter::remaining($userKey, max(1, (int) config('pedigree.ai.live.daily_per_user', 5)))];
    }

    // ------------------------------------------------------------------ کمکی‌ها

    private function check(string $provider, int $status, mixed $json): void
    {
        if ($status < 400 && is_array($json)) {
            return;
        }
        $detail = is_array($json) ? ($json['error']['message'] ?? $json['error'] ?? null) : null;
        Log::warning('AI voice provider error', ['provider' => $provider, 'status' => $status, 'detail' => is_string($detail) ? mb_substr($detail, 0, 300) : null]);

        throw match (true) {
            $status === 401, $status === 403 => new DomainException('سرویس هوش مصنوعی درخواست را نپذیرفت (کلید نامعتبر یا محدودیت منطقه‌ای). اگر سرور در ایران است، پراکسی لازم است.', 502, 'ai_auth'),
            $status === 429 => new DomainException('سرویس هوش مصنوعی فعلاً شلوغ است یا سهمیه آن تمام شده؛ کمی بعد دوباره امتحان کنید.', 503, 'ai_busy'),
            $status === 404 => new DomainException('مدل صوتی انتخاب‌شده پیدا نشد؛ مدیر سایت نام مدل را بررسی کند.', 502, 'ai_model'),
            default => new DomainException('سرویس صوتی هوش مصنوعی در دسترس نیست؛ کمی بعد دوباره امتحان کنید.', 502, 'ai_down'),
        };
    }

    /** تبدیل به Ogg/Opus شانزده کیلوهرتز تک‌کاناله (کوچک و پذیرفتنی برای همه سرویس‌ها) */
    private function toOgg(string $path): ?string
    {
        return $this->ffmpeg($path, ['-ac', '1', '-ar', '16000', '-c:a', 'libopus', '-b:a', '24k', '-f', 'ogg'], 'ogg');
    }

    /** @return array{0:string, 1:string}|null */
    private function toAac(string $wav): ?array
    {
        $in = tempnam(sys_get_temp_dir(), 'tts');
        file_put_contents($in, $wav);
        try {
            $out = $this->ffmpeg($in, ['-ac', '1', '-c:a', 'aac', '-b:a', '48k', '-movflags', '+faststart', '-f', 'mp4'], 'm4a');

            return $out === null ? null : [$out, 'audio/mp4'];
        } finally {
            @unlink($in);
        }
    }

    private function ffmpeg(string $in, array $args, string $ext): ?string
    {
        $out = tempnam(sys_get_temp_dir(), 'aiv').'.'.$ext;
        try {
            $p = new Process([(string) config('pedigree.media.video.ffmpeg', 'ffmpeg'), '-v', 'error', '-y', '-protocol_whitelist', 'file',
                '-format_whitelist', 'matroska,webm,ogg,mov,mp4,m4a,3gp,mp3,wav,aac,flac,amr', '-i', $in, '-vn', '-map_metadata', '-1', ...$args, $out]);
            $p->setTimeout(60);
            $p->run();

            return $p->isSuccessful() && is_file($out) && filesize($out) > 0 ? (string) file_get_contents($out) : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($out);
            @unlink(substr($out, 0, -strlen($ext) - 1));
        }
    }
}
