<?php

namespace App\Support;

/**
 * شبکه‌های اجتماعی پروفایل: یکدست‌سازی شناسه‌ها و ساخت لینک مستقیم.
 *
 * کاربر ممکن است هر شکلی وارد کند: «@ali.r»، «ali.r»، «instagram.com/ali.r»،
 * «https://www.instagram.com/ali.r/?hl=fa»، برای واتس‌اپ «۰۹۱۲...» یا «wa.me/98912...».
 * همه به یک شکل استاندارد تبدیل می‌شوند (شناسه بدون @ یا شماره بین‌المللی +98912...).
 * لینکِ دامنه‌ای جز دامنه‌های خود همان شبکه پذیرفته نمی‌شود (جلوگیری از لینک فیشینگ)
 * و لینک نهایی همیشه از روی الگوی ثابت سرور ساخته می‌شود، نه از متن کاربر.
 */
final class SocialNetworks
{
    /** @return array<string, array> */
    public static function all(): array
    {
        return config('pedigree.profile.social_networks', []);
    }

    public static function exists(string $network): bool
    {
        return isset(self::all()[$network]);
    }

    /**
     * شکل استاندارد مقدار؛ null یعنی نامعتبر
     */
    public static function normalize(string $network, mixed $value): ?string
    {
        $def = self::all()[$network] ?? null;
        if ($def === null || ! is_string($value)) {
            return null;
        }
        $value = PersianText::toLatinDigits(trim($value));
        // نویسه‌های نامرئی و فاصله‌ها
        $value = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\s]+/u', '', $value) ?? '';
        if ($value === '' || strlen($value) > 300) {
            return null;
        }

        $kind = $def['kind'] ?? 'handle';

        // لینک کامل: فقط از دامنه‌های خود شبکه
        if (preg_match('#^(?:https?://)?(?:www\.|m\.|mobile\.|web\.)?([a-z0-9.\-]+\.[a-z]{2,})(/.*)?$#i', $value, $m) && ! preg_match('/^\+?\d+$/', $value)) {
            $host = strtolower($m[1]);
            if (! in_array($host, $def['hosts'] ?? [], true)) {
                // «ali.reza» شبیه دامنه است ولی شناسه است؛ فقط اگر مسیر یا پروتکل داشت یعنی لینک
                if (isset($m[2]) || preg_match('#^https?://#i', $value)) {
                    return null;
                }
            } else {
                $value = self::fromUrl($network, $def, $m[2] ?? '');
                if ($value === null) {
                    return null;
                }
            }
        }

        if ($kind === 'phone' || ($kind === 'handle_or_phone' && preg_match('/^\+?[\d\-()]{7,20}$/', $value))) {
            return self::phone($value);
        }

        $value = ltrim($value, '@');
        $pattern = $def['pattern'] ?? '[A-Za-z0-9._\-]{1,60}';

        return preg_match('~^(?:'.$pattern.')$~', $value) ? $value : null;
    }

    /** شماره بین‌المللی با + (موبایل ایران: +989...) */
    public static function phone(string $value): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', $value) ?? '';
        if (preg_match('/^\d{8,15}$/', $digits) && ! str_starts_with($digits, '0')) {
            $digits = '+'.$digits; // wa.me/98912... بدون +
        }
        $normalized = Phone::normalize($digits);
        if ($normalized === null) {
            return null;
        }

        return str_starts_with($normalized, '0') ? '+98'.substr($normalized, 1) : $normalized;
    }

    /** لینک مستقیم پروفایل */
    public static function url(string $network, ?string $value): ?string
    {
        $def = self::all()[$network] ?? null;
        if ($def === null || $value === null || $value === '') {
            return null;
        }
        if (self::isPhone($network, $value)) {
            return match ($network) {
                'whatsapp' => 'https://wa.me/'.ltrim($value, '+'),
                'telegram' => 'https://t.me/'.$value,
                default => null,
            };
        }

        return str_replace('{h}', rawurlencode($value), (string) $def['url']);
    }

    /** مقدار، شماره تلفن است؟ (شماره‌ها مثل موبایل فقط برای مجازها نمایش داده می‌شوند) */
    public static function isPhone(string $network, ?string $value): bool
    {
        $kind = self::all()[$network]['kind'] ?? 'handle';

        return $kind === 'phone' || ($kind === 'handle_or_phone' && $value !== null && str_starts_with($value, '+'));
    }

    /** متن نمایشی: @ali.r یا +98 912 ... */
    public static function display(string $network, string $value): string
    {
        return self::isPhone($network, $value) ? $value : '@'.$value;
    }

    /** یکدست‌سازی همه شبکه‌ها؛ ورودی نامعتبر در errors برمی‌گردد */
    public static function normalizeAll(array $social, array &$errors = []): array
    {
        $out = [];
        foreach (self::all() as $network => $def) {
            $raw = $social[$network] ?? null;
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                continue;
            }
            $normalized = self::normalize($network, $raw);
            if ($normalized === null) {
                $errors[$network] = $def['label'];

                continue;
            }
            $out[$network] = $normalized;
        }

        return $out;
    }

    private static function fromUrl(string $network, array $def, string $path): ?string
    {
        $query = '';
        if (($q = strpos($path, '?')) !== false) {
            $query = substr($path, $q + 1);
            $path = substr($path, 0, $q);
        }
        $path = explode('#', $path)[0];
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        if ($network === 'whatsapp') {
            parse_str($query, $params);

            return is_string($params['phone'] ?? null) ? $params['phone'] : ($segments[0] ?? null);
        }
        if (isset($def['prefix']) && ($segments[0] ?? null) === $def['prefix']) {
            array_shift($segments);
        }
        // t.me/s/channel ← پیش‌نمایش کانال
        if ($network === 'telegram' && ($segments[0] ?? null) === 's') {
            array_shift($segments);
        }
        $handle = $segments[0] ?? null;

        return $handle === null ? null : ltrim(rawurldecode($handle), '@');
    }
}
