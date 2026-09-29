<?php

namespace App\Support;

use App\Models\ErrorReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * تور ایمنی خطاها: هر خطای پیش‌بینی‌نشده در هر بخش سایت (کنترلر، سرویس، صف، زمان‌بندی، مرورگر) اینجا
 * با یک «کد پیگیری» برای پنل مدیریت ثبت می‌شود و به کاربر فقط یک پیام فارسی کوتاه با همان کد نشان داده می‌شود.
 *
 *  - هرگز خودش خطا نمی‌دهد (اگر پایگاه‌داده هم در دسترس نباشد، بی‌صدا رد می‌شود)
 *  - پیام‌ها پاک‌سازی می‌شوند: آدرس‌ها فقط با نام دامنه، بدون مسیر و پارامتر (کلید API داخل آدرس لو نمی‌رود)،
 *    رشته‌های طولانی شبیه کلید/توکن و عددهای بلند (موبایل، کد ملی) پوشانده می‌شوند و مسیر پوشه سرور حذف می‌شود
 *  - خطای تکراری یک ردیف با شمارنده است و ثبت در پایگاه‌داده سقف دقیقه‌ای دارد (سیل خطا دیسک را پر نمی‌کند)
 */
final class ErrorReporter
{
    /** بیشترین ثبت در دقیقه (بقیه فقط شمرده نمی‌شوند) */
    private const MAX_WRITES_PER_MINUTE = 60;

    /** بیشترین ردیف نگه‌داشته‌شده */
    private const MAX_ROWS = 2000;

    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $refs = null;

    /** ثبت خطای سرور؛ کد پیگیری یا null */
    public static function record(Throwable $e, ?Request $request = null): ?string
    {
        try {
            $location = self::location($e);
            $ref = self::store([
                'source' => 'server',
                'type' => self::shortClass($e::class),
                'message' => self::sanitize($e->getMessage()) ?: '(بدون پیام)',
                'location' => $location,
                'path' => $request ? mb_substr('/'.ltrim($request->path(), '/'), 0, 255) : (app()->runningInConsole() ? 'console' : null),
                'method' => $request?->method(),
                'user_id' => $request?->user()?->id,
            ]);
            if ($ref !== null) {
                self::$refs ??= new WeakMap;
                self::$refs[$e] = $ref;
            }

            return $ref;
        } catch (Throwable) {
            return null;
        }
    }

    /** ثبت خطای مرورگر (از /api/client-errors) */
    public static function recordClient(array $data, ?int $userId): ?string
    {
        try {
            $file = self::sanitize((string) ($data['source'] ?? ''));
            $line = (int) ($data['line'] ?? 0);

            return self::store([
                'source' => 'client',
                'type' => mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) ($data['type'] ?? 'Error')) ?: 'Error', 0, 60),
                'message' => self::sanitize((string) ($data['message'] ?? '')) ?: '(بدون پیام)',
                'location' => $file !== '' ? mb_substr($file.($line > 0 ? ':'.$line : ''), 0, 255) : null,
                'path' => mb_substr(self::sanitize((string) ($data['page'] ?? '')), 0, 255) ?: null,
                'method' => null,
                'user_id' => $userId,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /** کد پیگیری خطایی که همین حالا ثبت شد (برای نمایش به کاربر) */
    public static function refFor(Throwable $e): ?string
    {
        return self::$refs[$e] ?? null;
    }

    /**
     * پاک‌سازی پیام خطا از هر چیز حساس
     */
    public static function sanitize(string $message): string
    {
        $message = str_replace([base_path().DIRECTORY_SEPARATOR, base_path()], '', $message);
        // آدرس‌ها: فقط دامنه (کلید API برخی پنل‌ها داخل مسیر آدرس است)
        $message = preg_replace_callback('#\b[a-z][a-z0-9+.-]*://([^/\s:@]+@)?([^/\s:?\#]+)(:\d+)?[^\s"\'<>]*#i', fn ($m) => '['.strtolower($m[2]).']', $message) ?? $message;
        // اتصال پایگاه‌داده و مسیرهای مطلق
        $message = preg_replace('/\((Connection|SQL): .*$/su', '(…)', $message) ?? $message;
        $message = preg_replace('#(?<![\w.])/(?:home|var|srv|usr|opt|tmp|root|etc)/[^\s"\']+#', '[path]', $message) ?? $message;
        // کلید و توکن و عددهای بلند (موبایل، کد ملی، کارت)
        $message = preg_replace('/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{24,}\b/', '***', $message) ?? $message;
        $message = preg_replace('/(?:\+?\d[\d\s-]{7,}\d)/', '***', $message) ?? $message;
        $message = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $message) ?? $message;

        return trim(mb_substr($message, 0, 500));
    }

    /** نخستین خط کد خود برنامه (نه کتابخانه‌ها) به صورت مسیر نسبی */
    private static function location(Throwable $e): ?string
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());
        foreach ($frames as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file !== '' && str_starts_with($file, $base) && ! str_starts_with($file, $base.'vendor')) {
                return mb_substr(str_replace($base, '', $file).':'.($frame['line'] ?? 0), 0, 255);
            }
        }

        return $e->getFile() !== '' ? mb_substr(basename($e->getFile()).':'.$e->getLine(), 0, 255) : null;
    }

    private static function store(array $row): ?string
    {
        // سقف دقیقه‌ای ثبت (در برابر سیل خطا)
        $key = 'error-reports:'.now()->format('YmdHi');
        Cache::add($key, 0, 120);
        if (Cache::increment($key) > self::MAX_WRITES_PER_MINUTE) {
            return null;
        }

        $normalized = preg_replace('/\d+/', '#', $row['message']);
        $hash = sha1($row['source'].'|'.$row['type'].'|'.$row['location'].'|'.$normalized);
        $now = now();
        $existing = ErrorReport::query()->where('hash', $hash)->first();
        if ($existing) {
            $existing->forceFill([
                'count' => min(4_000_000_000, $existing->count + 1),
                'last_seen_at' => $now,
                'resolved_at' => null,
                'path' => $row['path'] ?? $existing->path,
                'user_id' => $row['user_id'] ?? $existing->user_id,
            ])->save();

            return $existing->ref;
        }

        $report = ErrorReport::query()->create($row + [
            'hash' => $hash,
            'ref' => strtoupper(Str::random(6)),
            'count' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        if ($report->id % 50 === 0) {
            self::prune();
        }

        return $report->ref;
    }

    /** نگه‌داشتن حداکثر MAX_ROWS ردیف (حل‌شده‌ها و قدیمی‌ترها اول پاک می‌شوند) */
    private static function prune(): void
    {
        $total = ErrorReport::query()->count();
        if ($total <= self::MAX_ROWS) {
            return;
        }
        $extra = $total - self::MAX_ROWS;
        $ids = ErrorReport::query()->orderByRaw('resolved_at IS NULL')->orderBy('last_seen_at')->limit($extra)->pluck('id');
        ErrorReport::query()->whereIn('id', $ids)->delete();
    }

    private static function shortClass(string $class): string
    {
        return mb_substr(class_basename($class), 0, 120);
    }
}
