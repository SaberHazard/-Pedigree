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
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
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
                    "img-src 'self' data: blob:",
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
}
