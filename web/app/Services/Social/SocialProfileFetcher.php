<?php

namespace App\Services\Social;

use App\Exceptions\DomainException;
use App\Support\SocialNetworks;

/**
 * دریافت نام و عکس پروفایل عمومی از شبکه‌های اجتماعی.
 *
 * - تلگرام: صفحه عمومی t.me/شناسه (همان پیش‌نمایشی که پیام‌رسان‌ها نشان می‌دهند)
 * - اینستاگرام: اگر توکن رسمی Graph API تنظیم شده باشد از «Business Discovery» (فقط حساب‌های
 *   تجاری/تولیدکننده)، وگرنه تلاش با پیش‌نمایش صفحه عمومی (اینستاگرام اغلب آن را فقط به کاربر
 *   واردشده نشان می‌دهد؛ در این صورت عکس را دستی آپلود کنید)
 * - گیت‌هاب: API عمومی
 * - واتس‌اپ: هیچ راه عمومی برای دیدن عکس پروفایل ندارد؛ فقط لینک مستقیم گفتگو
 *
 * آدرس‌ها همیشه از روی شناسه‌ای ساخته می‌شوند که قبلاً با الگوی سخت‌گیرانه بررسی شده است.
 */
class SocialProfileFetcher
{
    public const FETCHABLE = ['instagram', 'telegram', 'github'];

    public function __construct(private readonly SafeHttp $http) {}

    public function canFetch(string $network, ?string $value): bool
    {
        return (bool) config('pedigree.social.fetch_enabled', true)
            && in_array($network, self::FETCHABLE, true)
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

        return match ($network) {
            'telegram' => $this->telegram($handle),
            'instagram' => $this->instagram($handle),
            'github' => $this->github($handle),
        };
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
