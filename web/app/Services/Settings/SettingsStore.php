<?php

namespace App\Services\Settings;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * تنظیمات ذخیره‌شده از پنل مدیریت.
 *
 * - همه مقدارها با کلید برنامه (APP_KEY) رمزنگاری‌شده در جدول settings ذخیره می‌شوند
 *   و در کش هم به همان شکل رمزنگاری‌شده می‌مانند (کلید API هرگز خام در کش/لاگ نیست).
 * - در ابتدای هر درخواست روی config اعمال می‌شوند و بر مقدار ‎.env مقدم‌اند.
 * - مقدار null = حذف از پنل و بازگشت به مقدار ‎.env
 */
class SettingsStore
{
    private const CACHE_KEY = 'app-settings:encrypted:v1';

    /** @var array<string, mixed> مقدار اولیه config پیش از اعمال پنل (برای نمایش «از ‎.env») */
    private array $baseline = [];

    private bool $applied = false;

    /** اعمال تنظیمات پنل روی config (بی‌صدا اگر جدول هنوز ساخته نشده) */
    public function apply(): void
    {
        try {
            $rows = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')->all());
        } catch (Throwable) {
            return; // پیش از اجرای migrate
        }
        $fields = SettingsSchema::fields();
        foreach ($rows as $key => $encrypted) {
            if (! isset($fields[$key])) {
                continue;
            }
            if (! array_key_exists($key, $this->baseline)) {
                $this->baseline[$key] = config($key);
            }
            try {
                config([$key => json_decode(Crypt::decryptString((string) $encrypted), true)]);
            } catch (Throwable) {
                // اگر APP_KEY عوض شده باشد مقدار قابل خواندن نیست؛ همان ‎.env استفاده می‌شود
                Log::warning('Setting could not be decrypted and was ignored', ['key' => $key]);
            }
        }
        $this->applied = true;
    }

    /** @return string[] کلیدهایی که در پنل مقدار دارند */
    public function storedKeys(): array
    {
        try {
            return DB::table('settings')->pluck('key')->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** مقدار .env (پیش از اعمال پنل) یک کلید */
    public function baseline(string $key): mixed
    {
        return array_key_exists($key, $this->baseline) ? $this->baseline[$key] : config($key);
    }

    /**
     * ذخیره چند مقدار (کلیدها قبلاً اعتبارسنجی شده‌اند). null = حذف از پنل
     *
     * @return string[] کلیدهای تغییرکرده
     */
    public function save(array $values, ?User $actor): array
    {
        $fields = SettingsSchema::fields();
        $changed = [];
        DB::transaction(function () use ($values, $actor, $fields, &$changed) {
            $existing = DB::table('settings')->whereIn('key', array_keys($values))->pluck('value', 'key')->all();
            foreach ($values as $key => $value) {
                if (! isset($fields[$key])) {
                    continue;
                }
                if ($value === null) {
                    if (array_key_exists($key, $existing)) {
                        DB::table('settings')->where('key', $key)->delete();
                        $changed[] = $key;
                    }

                    continue;
                }
                $old = null;
                if (isset($existing[$key])) {
                    try {
                        $old = json_decode(Crypt::decryptString($existing[$key]), true);
                    } catch (Throwable) {
                        $old = null;
                    }
                }
                if (array_key_exists($key, $existing) && $old === $value) {
                    continue;
                }
                DB::table('settings')->updateOrInsert(['key' => $key], [
                    'value' => Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE)),
                    'updated_by' => $actor?->id,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
                $changed[] = $key;
            }
        });

        if ($changed) {
            Cache::forget(self::CACHE_KEY);
            // مقدارهای حذف‌شده به .env برگردند و بقیه همین حالا اعمال شوند
            foreach ($changed as $key) {
                if (($values[$key] ?? null) === null && array_key_exists($key, $this->baseline)) {
                    config([$key => $this->baseline[$key]]);
                }
            }
            $this->apply();
        }

        return $changed;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
