<?php

namespace App\Services\Calls;

/**
 * لینک تماس سرویس‌های بیرونی (تماس گروهی بدون درگیر شدن سرور سایت):
 *  - واتس‌اپ: «لینک تماس» (call.whatsapp.com/voice|video/...) که در خود واتس‌اپ ساخته می‌شود؛ واتس‌اپ در حال
 *    گسترش ورود «مهمان» از مرورگر برای کسانی است که واتس‌اپ ندارند
 *  - گوگل‌میت، جیتسی (بدون نصب، در مرورگر)، اسکای‌روم (ایرانی)، زوم، ویدیوچت تلگرام
 *
 * فقط لینک‌های https همین دامنه‌ها با مسیر دقیق هر سرویس پذیرفته و بازنویسی می‌شوند (بدون نام کاربری، درگاه،
 * پارامتر اضافه یا fragment) تا لینک فریب یا ردیاب از طریق سایت پخش نشود.
 */
final class CallLinks
{
    public const PROVIDERS = [
        'whatsapp' => ['label' => 'تماس واتس‌اپ', 'hosts' => ['call.whatsapp.com'], 'path' => '#^/(voice|video)/[A-Za-z0-9_-]{8,64}$#', 'query' => null],
        'meet' => ['label' => 'گوگل‌میت', 'hosts' => ['meet.google.com'], 'path' => '#^/[a-z]{3}-[a-z]{4}-[a-z]{3}$#', 'query' => null],
        'jitsi' => ['label' => 'جیتسی', 'hosts' => ['meet.jit.si'], 'path' => '#^/[A-Za-z0-9_-]{6,80}$#', 'query' => null],
        'skyroom' => ['label' => 'اسکای‌روم', 'hosts' => ['skyroom.online', 'www.skyroom.online'], 'path' => '#^/ch/[A-Za-z0-9_-]{2,40}(/[A-Za-z0-9_-]{2,40})?$#', 'query' => null],
        'zoom' => ['label' => 'زوم', 'hosts' => ['zoom.us', 'us02web.zoom.us', 'us04web.zoom.us', 'us05web.zoom.us', 'us06web.zoom.us'], 'path' => '#^/j/\d{9,12}$#', 'query' => '#^pwd=[A-Za-z0-9._-]{1,64}$#'],
        'telegram' => ['label' => 'ویدیوچت تلگرام', 'hosts' => ['t.me'], 'path' => '#^/[A-Za-z][A-Za-z0-9_]{3,31}$#', 'query' => '#^videochat(=[A-Za-z0-9_-]{1,64})?$#'],
    ];

    /**
     * @return array{provider: string, url: string, label: string}|null
     */
    public static function parse(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 300 || preg_match('/[\s<>"\'\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? null;
        foreach (self::PROVIDERS as $key => $p) {
            if (! in_array($host, $p['hosts'], true) || ! preg_match($p['path'], $path)) {
                continue;
            }
            if ($query !== null && ($p['query'] === null || ! preg_match($p['query'], $query))) {
                return null;
            }

            return ['provider' => $key, 'url' => 'https://'.$host.$path.($query !== null ? '?'.$query : ''), 'label' => $p['label']];
        }

        return null;
    }
}
