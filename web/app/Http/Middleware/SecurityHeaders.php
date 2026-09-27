<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * هدرهای امنیتی برای همه پاسخ‌ها.
 *
 * - CSP سخت‌گیرانه با nonce: فقط اسکریپت‌های خود سایت اجرا می‌شوند (سد اصلی در برابر XSS)
 * - جلوگیری از نمایش سایت داخل iframe سایت‌های دیگر (Clickjacking)
 * - HSTS روی HTTPS
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        app()->instance('csp-nonce', $nonce);

        /** @var Response $response */
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // دوربین و میکروفون برای ضبط استوری، موقعیت مکانی برای «موقعیت من» روی نقشه
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $headers->has('Content-Security-Policy')) {
            $isHtml = str_contains((string) $headers->get('Content-Type'), 'text/html');
            $headers->set('Content-Security-Policy', $isHtml
                ? implode('; ', [
                    "default-src 'self'",
                    "script-src 'self' 'nonce-{$nonce}'",
                    "style-src 'self' 'unsafe-inline'",
                    "img-src 'self' data: blob:".self::tileOrigin(),
                    "media-src 'self' blob:",
                    "font-src 'self' data:",
                    "connect-src 'self'",
                    "worker-src 'self'",
                    "manifest-src 'self'",
                    "frame-ancestors 'self'",
                    "base-uri 'self'",
                    "form-action 'self'",
                    "object-src 'none'",
                ])
                : "default-src 'none'; frame-ancestors 'none'");
        }

        return $response;
    }

    /** دامنه سرویس کاشی نقشه (مثلاً https://tile.openstreetmap.org) برای مجاز شدن تصاویر نقشه */
    private static function tileOrigin(): string
    {
        $url = (string) config('pedigree.map.tiles');
        if (! config('pedigree.map.enabled', true) || $url === '') {
            return '';
        }
        $parts = parse_url(str_replace(['{s}', '{z}', '{x}', '{y}', '{r}'], ['a', '0', '0', '0', ''], $url));
        if (! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['https', 'http'], true)) {
            return '';
        }
        $host = str_starts_with((string) parse_url($url, PHP_URL_HOST), '{s}.') ? '*.'.substr($parts['host'], 2) : $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ' '.$parts['scheme'].'://'.$host.$port;
    }
}
