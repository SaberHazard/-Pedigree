<?php

namespace App\Services\Social;

use App\Exceptions\DomainException;
use App\Support\SocialNetworks;
use Illuminate\Support\Facades\RateLimiter;

/**
 * دریافت نام و عکس پروفایل عمومی از شبکه‌های اجتماعی.
 *
 * - تلگرام: صفحه عمومی t.me/شناسه (همان پیش‌نمایشی که پیام‌رسان‌ها نشان می‌دهند)
 * - اینستاگرام: اگر توکن رسمی Graph API تنظیم شده باشد از «Business Discovery» (فقط حساب‌های
 *   تجاری/تولیدکننده)، وگرنه تلاش با پیش‌نمایش صفحه عمومی (اینستاگرام اغلب آن را فقط به کاربر
 *   واردشده نشان می‌دهد؛ در این صورت عکس را دستی آپلود کنید)
 * - گیت‌هاب، بلواسکای و آپارات: API عمومی بدون کلید
 * - ایکس (توییتر) و یوتیوب: API رسمی با کلیدی که مدیر در پنل وارد می‌کند
 * - واتس‌اپ: هیچ راه عمومی برای دیدن عکس پروفایل ندارد؛ فقط لینک مستقیم گفتگو
 *
 * آدرس‌ها همیشه از روی شناسه‌ای ساخته می‌شوند که قبلاً با الگوی سخت‌گیرانه بررسی شده است.
 */
class SocialProfileFetcher
{
    public const FETCHABLE = ['instagram', 'telegram', 'github', 'bluesky', 'aparat', 'x', 'youtube'];

    public function __construct(private readonly SafeHttp $http) {}

    /** این شبکه با تنظیمات فعلی قابل دریافت خودکار است؟ (ایکس و یوتیوب فقط با کلید) */
    public function supports(string $network): bool
    {
        return (bool) config('pedigree.social.fetch_enabled', true)
            && in_array($network, self::FETCHABLE, true)
            && match ($network) {
                'x' => (bool) config('pedigree.social.x.bearer_token'),
                'youtube' => (bool) config('pedigree.social.youtube.api_key'),
                default => true,
            };
    }

    public function canFetch(string $network, ?string $value): bool
    {
        return $this->supports($network)
            && $value !== null && $value !== ''
            && ! SocialNetworks::isPhone($network, $value)
            && SocialNetworks::normalize($network, $value) === $value;
    }

    /**
     * @return array{name:?string, image:?string} image = باینری عکس
     */
    public function fetch(string $network, string $handle): array
    {
        if (! $this->canFetch($network, $handle)) {
            throw new DomainException('دریافت خودکار عکس برای این شبکه ممکن نیست.');
        }
        // سقف کل درخواست‌های سرور به هر شبکه (تا IP سرور از طرف شبکه‌ها مسدود نشود و سرور ابزار حمله نشود)
        $limit = (int) config('pedigree.social.hourly_limit', 300);
        if (! RateLimiter::attempt('social-fetch:'.$network, $limit, fn () => true, 3600)) {
            throw new DomainException('تعداد درخواست‌ها به این شبکه زیاد شده است؛ کمی بعد دوباره امتحان کنید.', 429);
        }

        return match ($network) {
            'telegram' => $this->telegram($handle),
            'instagram' => $this->instagram($handle),
            'github' => $this->github($handle),
            'bluesky' => $this->bluesky($handle),
            'aparat' => $this->aparat($handle),
            'x' => $this->x($handle),
            'youtube' => $this->youtube($handle),
        };
    }

    private function bluesky(string $handle): array
    {
        $actor = str_contains($handle, '.') ? $handle : $handle.'.bsky.social';
        $data = $this->json('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?'.http_build_query(['actor' => $actor]), ['public.api.bsky.app']);
        $url = $data['avatar'] ?? null;

        return [
            'name' => is_string($data['displayName'] ?? null) && $data['displayName'] !== '' ? $data['displayName'] : null,
            'image' => is_string($url) ? $this->image($url, 'bluesky') : null,
        ];
    }

    private function aparat(string $handle): array
    {
        $data = $this->json('https://www.aparat.com/etc/api/profile/username/'.rawurlencode($handle), ['www.aparat.com']);
        $profile = $data['profile'] ?? [];
        $url = $profile['pic_b'] ?? $profile['pic_m'] ?? $profile['pic_s'] ?? null;

        return [
            'name' => is_string($profile['name'] ?? null) ? $profile['name'] : null,
            'image' => is_string($url) ? $this->image($url, 'aparat') : null,
        ];
    }

    private function x(string $handle): array
    {
        $data = $this->json(
            'https://api.x.com/2/users/by/username/'.rawurlencode($handle).'?user.fields=profile_image_url,name',
            ['api.x.com'],
            ['Authorization' => 'Bearer '.config('pedigree.social.x.bearer_token')],
        );
        $user = $data['data'] ?? [];
        $url = $user['profile_image_url'] ?? null;

        return [
            'name' => is_string($user['name'] ?? null) ? $user['name'] : null,
            // «_normal» عکس ۴۸ پیکسلی است؛ نسخه بزرگ‌تر
            'image' => is_string($url) ? $this->image(str_replace('_normal.', '_400x400.', $url), 'x') : null,
        ];
    }

    private function youtube(string $handle): array
    {
        $data = $this->json('https://www.googleapis.com/youtube/v3/channels?'.http_build_query([
            'part' => 'snippet',
            'forHandle' => '@'.$handle,
            'key' => (string) config('pedigree.social.youtube.api_key'),
        ]), ['www.googleapis.com']);
        $snippet = $data['items'][0]['snippet'] ?? null;
        if (! is_array($snippet)) {
            throw new DomainException('این کانال پیدا نشد.', 404);
        }
        $thumbs = $snippet['thumbnails'] ?? [];
        $url = $thumbs['high']['url'] ?? $thumbs['medium']['url'] ?? $thumbs['default']['url'] ?? null;

        return [
            'name' => is_string($snippet['title'] ?? null) ? $snippet['title'] : null,
            'image' => is_string($url) ? $this->image($url, 'youtube') : null,
        ];
    }

    /** پاسخ JSON یک API (فقط از دامنه‌های مجاز) */
    private function json(string $url, array $hosts, array $headers = []): array
    {
        $res = $this->http->get($url, $hosts, 512 * 1024, $headers + ['Accept' => 'application/json']);
        if ($res['status'] === 404 || $res['status'] === 400) {
            throw new DomainException('این حساب پیدا نشد.', 404);
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            throw new DomainException('کلید API این شبکه نامعتبر است یا دسترسی ندارد.', 502);
        }
        if ($res['status'] !== 200) {
            throw new DomainException('این شبکه پاسخ نداد (کد '.$res['status'].').', 502);
        }
        $data = json_decode($res['body'], true);

        return is_array($data) ? $data : [];
    }

    private function telegram(string $handle): array
    {
        $page = $this->page('https://t.me/'.$handle, ['t.me']);
        $og = self::openGraph($page);
        $name = $og['title'] ?? null;
        // صفحه پیش‌فرض (شناسه بدون نام یا ناموجود): «Telegram: Contact @x» با لوگوی تلگرام
        if ($name !== null && preg_match('/^Telegram: (Contact|View|Join)/i', $name)) {
            $name = null;
        }
        $image = $og['image'] ?? null;
        if ($image !== null && (str_contains($image, 't_logo') || str_contains($image, 'telegram.org/img'))) {
            $image = null;
        }

        return ['name' => $name, 'image' => $image ? $this->image($image, 'telegram') : null];
    }

    private function instagram(string $handle): array
    {
        $token = config('pedigree.social.instagram.graph_token');
        $userId = config('pedigree.social.instagram.graph_user_id');
        if ($token && $userId && preg_match('/^\d+$/', (string) $userId)) {
            $query = http_build_query([
                'fields' => "business_discovery.username({$handle}){name,username,profile_picture_url}",
                'access_token' => $token,
            ]);
            $res = $this->http->get("https://graph.facebook.com/v21.0/{$userId}?{$query}", ['graph.facebook.com'], 256 * 1024, ['Accept' => 'application/json']);
            $data = json_decode($res['body'], true)['business_discovery'] ?? null;
            if (is_array($data)) {
                $url = $data['profile_picture_url'] ?? null;

                return [
                    'name' => is_string($data['name'] ?? null) ? $data['name'] : null,
                    'image' => is_string($url) ? $this->image($url, 'instagram') : null,
                ];
            }
        }

        $page = $this->page("https://www.instagram.com/{$handle}/", ['www.instagram.com']);
        $og = self::openGraph($page);
        $name = $og['title'] ?? null;
        // «Ali Rezaei (@ali.r) • Instagram photos and videos»
        if ($name !== null) {
            $name = preg_match('/^(.*?)\s*\(@/u', $name, $m) ? trim($m[1]) : null;
        }
        $image = $og['image'] ?? null;

        return ['name' => $name ?: null, 'image' => $image ? $this->image($image, 'instagram') : null];
    }

    private function github(string $handle): array
    {
        $res = $this->http->get("https://api.github.com/users/{$handle}", ['api.github.com'], 256 * 1024, ['Accept' => 'application/vnd.github+json']);
        if ($res['status'] !== 200) {
            throw new DomainException('این حساب پیدا نشد.', 404);
        }
        $data = json_decode($res['body'], true) ?: [];
        $url = $data['avatar_url'] ?? null;

        return [
            'name' => is_string($data['name'] ?? null) ? $data['name'] : null,
            'image' => is_string($url) ? $this->image($url, 'github') : null,
        ];
    }

    private function page(string $url, array $hosts): string
    {
        $res = $this->http->get($url, $hosts, (int) config('pedigree.social.max_html_kb', 768) * 1024, ['Accept' => 'text/html']);
        if ($res['status'] === 404) {
            throw new DomainException('این حساب پیدا نشد.', 404);
        }
        if ($res['status'] !== 200) {
            throw new DomainException('صفحه این حساب در دسترس نیست (ممکن است خصوصی باشد یا شبکه اجازه ندهد).', 502);
        }

        return $res['body'];
    }

    /** دانلود عکس فقط از CDN خود همان شبکه */
    private function image(string $url, string $network): ?string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
        $hosts = config("pedigree.social.image_hosts.{$network}", []);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! $hosts || ! SafeHttp::hostAllowed($host, $hosts)) {
            return null;
        }
        $res = $this->http->get($url, $hosts, (int) config('pedigree.social.max_image_kb', 6144) * 1024, ['Accept' => 'image/*']);
        if ($res['status'] !== 200 || ! in_array($res['type'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || $res['body'] === '') {
            return null;
        }

        return $res['body'];
    }

    /**
     * @return array{title?:string, image?:string}
     */
    public static function openGraph(string $html): array
    {
        $out = [];
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (! preg_match('/\b(?:property|name)\s*=\s*["\'](og:title|og:image|twitter:image)["\']/i', $tag, $p)) {
                    continue;
                }
                if (! preg_match('/\bcontent\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $c)) {
                    continue;
                }
                $key = strtolower($p[1]) === 'og:title' ? 'title' : 'image';
                $value = trim(html_entity_decode($c[1] !== '' ? $c[1] : ($c[2] ?? ''), ENT_QUOTES | ENT_HTML5));
                if ($value !== '' && ! isset($out[$key])) {
                    $out[$key] = mb_substr($value, 0, $key === 'title' ? 120 : 2000);
                }
            }
        }

        return $out;
    }
}
