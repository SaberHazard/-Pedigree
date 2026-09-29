<?php

namespace App\Services\Social;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * درخواست HTTP امن به سرورهای بیرونی (جلوگیری از SSRF).
 *
 * - فقط https و فقط دامنه‌های فهرست مجاز (دقیق یا زیردامنه)
 * - نام دامنه پیش از اتصال resolve می‌شود و اگر به IP خصوصی/داخلی/رزروشده برسد رد می‌شود؛
 *   اتصال به همان IP بررسی‌شده «پین» می‌شود (جلوگیری از DNS rebinding)
 * - ریدایرکت خودکار خاموش است؛ حداکثر ۳ ریدایرکت دستی و هر مقصد دوباره بررسی می‌شود
 * - سقف حجم پاسخ (پیش از دانلود کامل قطع می‌شود) و سقف زمان
 */
class SafeHttp
{
    /** اتصال مستقیم (بدون پراکسی خروجی شبکه‌های اجتماعی)، مثلاً برای درگاه‌های پرداخت ایرانی */
    public const DIRECT = 'direct';

    /** @var (Closure(string): array<int,string>)|null برای تست */
    private static ?Closure $resolver = null;

    public static function fakeResolver(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @param  string[]  $allowedHosts
     * @return array{status:int, body:string, type:string}
     */
    public function get(string $url, array $allowedHosts, int $maxBytes, array $headers = [], int $maxRedirects = 3): array
    {
        return $this->request('GET', $url, $allowedHosts, $maxBytes, $headers, null, $maxRedirects);
    }

    /**
     * ارسال JSON (بدون دنبال کردن ریدایرکت تا بدنه درخواست به جای دیگری فرستاده نشود)
     *
     * @param  string[]  $allowedHosts
     * @return array{status:int, body:string, type:string}
     */
    public function postJson(string $url, array $allowedHosts, array $json, int $maxBytes, array $headers = [], ?string $proxy = null, ?int $timeout = null): array
    {
        return $this->request('POST', $url, $allowedHosts, $maxBytes, $headers, $json, 0, $proxy ?? '', $timeout);
    }

    /**
     * ارسال فرم چندبخشی با فایل (مثلاً فایل صوتی برای تبدیل گفتار به متن)؛ بدون ریدایرکت
     *
     * @param  string[]  $allowedHosts
     * @param  array<string, string>  $fields
     * @param  array<string, array{0:string, 1:string, 2:string}>  $files  نام ← [محتوا، نام فایل، نوع]
     * @return array{status:int, body:string, type:string}
     */
    public function postMultipart(string $url, array $allowedHosts, array $fields, array $files, int $maxBytes, array $headers = [], ?string $proxy = null, ?int $timeout = null): array
    {
        return $this->request('POST', $url, $allowedHosts, $maxBytes, $headers, null, 0, $proxy ?? '', $timeout, ['fields' => $fields, 'files' => $files]);
    }

    /**
     * @param  string[]  $allowedHosts
     * @param  ?string  $proxy  خالی = همان پراکسی شبکه‌های اجتماعی (اگر تنظیم شده باشد)؛ self::DIRECT = بدون پراکسی
     * @return array{status:int, body:string, type:string}
     */
    private function request(string $method, string $url, array $allowedHosts, int $maxBytes, array $headers, ?array $json, int $maxRedirects, ?string $proxy = null, ?int $timeout = null, ?array $multipart = null): array
    {
        $proxy = match (true) {
            $proxy === self::DIRECT => null,
            $proxy === null || $proxy === '' => config('pedigree.social.proxy'),
            default => $proxy,
        };
        $timeout ??= (int) config('pedigree.social.timeout', 8);

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            [$host, $ip] = $this->check($url, $allowedHosts, (bool) $proxy);

            $options = [
                'allow_redirects' => false,
                'timeout' => $timeout,
                'connect_timeout' => min(8, $timeout),
                'stream' => true,
                'http_errors' => false,
            ];
            if ($proxy) {
                $options['proxy'] = $proxy;
            } elseif ($ip !== null && defined('CURLOPT_RESOLVE')) {
                $options['curl'] = [
                    CURLOPT_RESOLVE => [$host.':443:'.(str_contains($ip, ':') ? "[{$ip}]" : $ip)],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                ];
            }

            try {
                $pending = Http::withOptions($options)->withHeaders($headers + [
                    'User-Agent' => 'Mozilla/5.0 (compatible; PedigreeLinkPreview/1.0)',
                    'Accept-Language' => 'fa,en;q=0.8',
                ]);
                if ($method === 'POST' && $multipart !== null) {
                    $pending = $pending->asMultipart();
                    foreach ($multipart['files'] as $name => [$contents, $filename, $mime]) {
                        $pending = $pending->attach($name, $contents, $filename, ['Content-Type' => $mime]);
                    }
                    $response = $pending->post($url, $multipart['fields']);
                } else {
                    $response = $method === 'POST' ? $pending->asJson()->post($url, $json ?? []) : $pending->get($url);
                }
            } catch (Throwable) {
                throw new DomainException('اتصال به سرور بیرونی برقرار نشد.', 502);
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = (string) $response->header('Location');
                if ($location === '' || $hop === $maxRedirects) {
                    break;
                }
                $url = $this->absolute($location, $url);

                continue;
            }

            $length = (int) $response->header('Content-Length');
            if ($length > $maxBytes) {
                throw new DomainException('پاسخ سرور بیش از حد بزرگ است.', 502);
            }
            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = '';
            while (! $stream->eof()) {
                $body .= $stream->read(65536);
                if (strlen($body) > $maxBytes) {
                    $stream->close();
                    throw new DomainException('پاسخ سرور بیش از حد بزرگ است.', 502);
                }
            }

            return [
                'status' => $response->status(),
                'body' => $body,
                'type' => strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])),
            ];
        }

        throw new DomainException('تعداد تغییر مسیرها بیش از حد مجاز است.', 502);
    }

    /**
     * @return array{0:string, 1:?string} [دامنه، IP بررسی‌شده]
     */
    private function check(string $url, array $allowedHosts, bool $viaProxy): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new DomainException('آدرس مجاز نیست.', 422);
        }
        if (! self::hostAllowed($host, $allowedHosts)) {
            throw new DomainException('دامنه مجاز نیست.', 422);
        }
        // IP مستقیم به جای دامنه پذیرفته نمی‌شود
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            throw new DomainException('آدرس مجاز نیست.', 422);
        }
        if ($viaProxy) {
            return [$host, null];
        }

        $ips = self::$resolver ? (self::$resolver)($host) : $this->resolve($host);
        if (! $ips) {
            throw new DomainException('نام دامنه پیدا نشد.', 502);
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new DomainException('آدرس مجاز نیست.', 422);
            }
        }
        // IPv4 مقدم است
        usort($ips, fn ($a, $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return [$host, $ips[0]];
    }

    public static function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    public static function isPublicIp(string $ip): bool
    {
        // IPv4 نگاشته‌شده در IPv6 (::ffff:127.0.0.1)
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            // شبکه‌های مشترک/آزمایشی که در فیلتر بالا نیستند
            && ! preg_match('/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|192\.0\.0\.|198\.1[89]\.|0\.)/', $ip);
    }

    /** @return string[] */
    private function resolve(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }
        $parts = parse_url($base);
        $origin = 'https://'.($parts['host'] ?? '');

        return str_starts_with($location, '/') ? $origin.$location : $origin.'/'.$location;
    }
}
