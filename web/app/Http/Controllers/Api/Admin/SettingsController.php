<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Push\FcmClient;
use App\Services\Settings\SettingsSchema;
use App\Services\Settings\SettingsStore;
use App\Services\Sms\SmsException;
use App\Services\Sms\SmsManager;
use App\Services\Social\SocialProfileFetcher;
use App\Support\SocialNetworks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «تنظیمات و اتصال‌ها» در پنل مدیریت (فقط مدیر کل):
 * کلیدهای API پنل‌های پیامکی، Firebase، شبکه‌های اجتماعی، نقشه و قوانین پیامک تبریک و اعلان تولد.
 *
 * - کلیدها و رمزها هرگز به مرورگر برنمی‌گردند (فقط «تنظیم شده» و چهار نویسه آخر)
 * - فقط کلیدهای فهرست SettingsSchema پذیرفته می‌شوند
 * - در تاریخچه فقط نام تنظیم‌های تغییرکرده ثبت می‌شود، نه مقدارشان
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsStore $store,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['data' => $this->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $fields = SettingsSchema::fields();
        $input = $request->validate([
            'values' => ['present', 'array'],
            'clear' => ['sometimes', 'array'],
            'clear.*' => ['string', Rule::in(array_keys($fields))],
        ]);

        $values = [];
        $errors = [];
        foreach ($input['values'] as $key => $value) {
            if (! isset($fields[$key])) {
                $errors["values.{$key}"] = ['این تنظیم وجود ندارد.'];

                continue;
            }
            // کلید خالی = بدون تغییر (برای حذف از clear استفاده می‌شود)
            if (SettingsSchema::isSecret($key) && ($value === null || $value === '')) {
                continue;
            }
            try {
                $values[$key] = $this->normalize($key, $fields[$key], $value);
            } catch (ValidationException $e) {
                $errors["values.{$key}"] = [$e->getMessage()];
            }
        }
        foreach ($input['clear'] ?? [] as $key) {
            $values[$key] = null;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $changed = $this->store->save($values, $request->user());
        if ($changed) {
            $this->audit->log('settings.updated', null, ['keys' => $changed], $request->user());
        }

        return response()->json(['data' => $this->present(), 'changed' => $changed, 'message' => $changed ? 'تنظیمات ذخیره شد.' : 'تغییری نبود.']);
    }

    /** آزمایش اتصال: اعتبار پنل پیامکی، پیامک آزمایشی به موبایل خود مدیر، Firebase، شبکه اجتماعی */
    public function test(Request $request, SmsManager $sms, FcmClient $fcm, SocialProfileFetcher $social): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['sms_credit', 'sms_send', 'push', 'social'])],
            'provider' => ['required_if:action,sms_credit,sms_send', 'nullable', Rule::in(SmsManager::names())],
            'network' => ['required_if:action,social', 'nullable', Rule::in(SocialProfileFetcher::FETCHABLE)],
            'handle' => ['required_if:action,social', 'nullable', 'string', 'max:200'],
        ]);

        try {
            $message = match ($data['action']) {
                'sms_credit' => $this->credit($sms, $data['provider']),
                'sms_send' => $this->sendTest($request, $sms, $data['provider']),
                'push' => $fcm->test(),
                'social' => $this->socialTest($social, $data['network'], (string) $data['handle']),
            };
        } catch (SmsException|DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 200) ?: 'آزمایش ناموفق بود.'], 422);
        }

        return response()->json(['ok' => true, 'message' => $message]);
    }

    // ------------------------------------------------------------------ کمکی‌ها

    private function credit(SmsManager $sms, string $provider): string
    {
        $credit = $sms->driver($provider)->credit();

        return 'اتصال برقرار است. اعتبار: '.number_format($credit['amount']).' '.$credit['unit'];
    }

    private function sendTest(Request $request, SmsManager $sms, string $provider): string
    {
        $phone = $request->user()->person?->phone;
        if (! $phone) {
            throw new DomainException('برای پیامک آزمایشی، ابتدا شماره موبایل خودتان را در پروفایلتان ثبت کنید.');
        }
        $sms->driver($provider)->send($phone, 'پیامک آزمایشی '.config('pedigree.site_name').' — اتصال پنل پیامکی درست است.');

        return 'پیامک آزمایشی به شماره خودتان ارسال شد.';
    }

    private function socialTest(SocialProfileFetcher $social, string $network, string $handle): string
    {
        $value = SocialNetworks::normalize($network, $handle);
        if ($value === null) {
            throw new DomainException('شناسه وارد شده معتبر نیست.');
        }
        $profile = $social->fetch($network, $value);

        return 'پاسخ دریافت شد: '.($profile['name'] ? "نام «{$profile['name']}»، " : '').($profile['image'] ? 'عکس پروفایل پیدا شد.' : 'عکس پروفایل عمومی پیدا نشد.');
    }

    /** @throws ValidationException */
    private function normalize(string $key, array $field, mixed $value): mixed
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$key => $message]);

        switch ($field['type']) {
            case 'bool':
                if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    $fail('مقدار باید روشن یا خاموش باشد.');
                }

                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'int':
                if (! is_numeric($value) || (int) $value != $value) {
                    $fail('یک عدد صحیح وارد کنید.');
                }
                $int = (int) $value;
                if ($int < ($field['min'] ?? PHP_INT_MIN) || $int > ($field['max'] ?? PHP_INT_MAX)) {
                    $fail("عدد باید بین {$field['min']} و {$field['max']} باشد.");
                }

                return $int;
            case 'select':
                if (! is_string($value) || ! array_key_exists($value, $field['options'])) {
                    $fail('گزینه انتخاب‌شده معتبر نیست.');
                }

                return $value;
            case 'url':
                $value = is_string($value) ? trim($value) : '';
                if (strlen($value) > 500 || ! preg_match('#^https://[^\s"\'<>]+$#i', $value) || ! parse_url($value, PHP_URL_HOST)) {
                    $fail('یک آدرس https معتبر وارد کنید.');
                }
                if (($field['kind'] ?? null) === 'tiles' && (! str_contains($value, '{z}') || ! str_contains($value, '{x}') || ! str_contains($value, '{y}'))) {
                    $fail('آدرس کاشی باید {z}، {x} و {y} داشته باشد.');
                }

                return $value;
            case 'secret_json':
                $decoded = is_string($value) ? json_decode($value, true) : (is_array($value) ? $value : null);
                if (! is_array($decoded) || strlen((string) json_encode($decoded)) > 20000) {
                    $fail('محتوای JSON معتبر نیست.');
                }
                foreach ($field['required_keys'] ?? [] as $required) {
                    if (empty($decoded[$required]) || ! is_string($decoded[$required])) {
                        $fail("کلید «{$required}» در فایل JSON نیست.");
                    }
                }

                return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            case 'secret':
            case 'text':
            default:
                if (! is_string($value) && ! is_numeric($value)) {
                    $fail('متن معتبر نیست.');
                }
                $value = trim((string) $value);
                if (mb_strlen($value) > ($field['max'] ?? 4000) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                    $fail('متن بیش از حد طولانی است یا نویسه نامعتبر دارد.');
                }
                if (isset($field['pattern']) && ! preg_match($field['pattern'], $value)) {
                    $fail('قالب مقدار درست نیست.');
                }
                if (($field['kind'] ?? null) === 'proxy' && $value !== '' && ! preg_match('#^(https?|socks5h?)://[^\s]+$#i', $value)) {
                    $fail('پراکسی باید با http://، https://، socks5:// یا socks5h:// شروع شود.');
                }

                return $value;
        }
    }

    /** ساختار گروه‌ها با مقدار فعلی (کلیدها ماسک‌شده) و وضعیت هر گروه */
    private function present(): array
    {
        $stored = array_flip($this->store->storedKeys());
        $sms = app(SmsManager::class);
        $groups = [];
        foreach (SettingsSchema::groups() as $groupKey => $group) {
            $fields = [];
            $missingSecrets = 0;
            foreach ($group['fields'] as $key => $field) {
                $value = config($key);
                $item = [
                    'key' => $key,
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'help' => $field['help'] ?? null,
                    'placeholder' => $field['placeholder'] ?? null,
                    'link' => $field['link'] ?? null,
                    'min' => $field['min'] ?? null,
                    'max' => $field['max'] ?? null,
                    'options' => isset($field['options']) ? array_map(fn ($v, $k) => ['value' => (string) $k, 'label' => $v], $field['options'], array_keys($field['options'])) : null,
                    'source' => isset($stored[$key]) ? 'panel' : 'env',
                ];
                if (SettingsSchema::isSecret($key)) {
                    $string = is_array($value) ? json_encode($value) : (string) $value;
                    $item['is_set'] = $string !== '';
                    $item['hint'] = $string !== '' ? '••••'.mb_substr($string, -4) : null;
                    if ($field['type'] === 'secret_json' && $string !== '') {
                        $item['hint'] = 'پروژه: '.(json_decode($string, true)['project_id'] ?? '؟');
                    }
                    $missingSecrets += $string === '' ? 1 : 0;
                } else {
                    $item['value'] = $field['type'] === 'bool' ? (bool) $value : $value;
                }
                $fields[] = $item;
            }

            $status = null;
            if (isset($group['provider'])) {
                $roles = [];
                if ($sms->driverName() === $group['provider']) {
                    $roles[] = 'کد ورود';
                }
                if ($sms->messageDriverName() === $group['provider']) {
                    $roles[] = 'پیامک تبریک';
                }
                $status = ['configured' => $missingSecrets === 0, 'roles' => $roles];
            } elseif ($groupKey === 'push') {
                $status = ['configured' => (bool) config('services.fcm.enabled') && $missingSecrets === 0, 'roles' => []];
            }

            $groups[] = [
                'key' => $groupKey,
                'label' => $group['label'],
                'icon' => $group['icon'],
                'description' => $group['description'] ?? null,
                'link' => $group['link'] ?? null,
                'provider' => $group['provider'] ?? null,
                'status' => $status,
                'fields' => $fields,
            ];
        }

        return $groups;
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        if (! $request->user()?->isSuperAdmin()) {
            throw new DomainException('فقط مدیر کل به تنظیمات و کلیدهای API دسترسی دارد.', 403);
        }
    }
}
