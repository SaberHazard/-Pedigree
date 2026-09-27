<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PersonRequest;
use App\Http\Resources\UserResource;
use App\Models\Device;
use App\Models\Person;
use App\Services\AuditLogger;
use App\Services\Auth\OtpService;
use App\Support\BlindIndex;
use App\Support\PersianText;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * تنظیمات حساب کاربری: رمز عبور، تغییر موبایل، نشست‌ها، ترجیحات نمایش، دستگاه‌ها
 */
class AccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** ذخیره ترجیحات نمایشی (تم، متن‌های دور دایره و ...) */
    public function preferences(Request $request): UserResource
    {
        $data = $request->validate([
            'theme' => ['nullable', 'in:light,dark,auto'],
            'arc_top' => ['nullable', 'in:name,fullname,nickname,none'],
            'arc_bottom' => ['nullable', 'in:dates,years,place,occupation,none'],
            'children_order' => ['nullable', 'in:rtl,ltr'],
            'show_photos' => ['nullable', 'boolean'],
            'calendar' => ['nullable', 'in:jalali,gregorian'],
            'compact' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $user->preferences = array_merge($user->preferences ?? [], array_filter($data, fn ($v) => $v !== null));
        $user->save();

        return new UserResource($user->load('person'));
    }

    /**
     * تعیین/تغییر رمز عبور.
     * رمز فعلی لازم است، مگر اینکه کاربر رمز نداشته یا همین الان با پیامک وارد شده باشد.
     */
    public function password(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:100', 'confirmed', Password::min((int) config('pedigree.password.min_length', 8))->letters()->numbers()],
        ]);

        $grace = (int) config('pedigree.password.otp_grace_minutes', 15);
        $recentOtp = $user->otp_verified_at && $user->otp_verified_at->gt(now()->subMinutes($grace));

        if ($user->password && ! $recentOtp) {
            if (empty($data['current_password']) || ! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'رمز عبور فعلی نادرست است.']);
            }
        }

        $user->password = $data['password'];
        $user->password_changed_at = now();
        $user->password_set_by = $user->id;
        $user->save();

        // خروج از سایر دستگاه‌ها پس از تغییر رمز
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))->delete();

        $this->audit->log('account.password_changed', $user->person, [], $user);

        return response()->json(['message' => 'رمز عبور با موفقیت ذخیره شد.']);
    }

    /** تعیین یا حذف نام کاربری (برای ورود با نام کاربری + رمز) */
    public function username(Request $request): JsonResponse
    {
        $user = $request->user();
        if (is_string($request->input('username'))) {
            $request->merge(['username' => PersonRequest::normalizeUsername($request->input('username'))]);
        }
        $data = $request->validate([
            'username' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9._-]*$/', 'min:'.(int) config('pedigree.username.min', 3),
                'max:'.(int) config('pedigree.username.max', 30), Rule::notIn(config('pedigree.username.reserved', [])),
                Rule::unique('users', 'username')->ignore($user->id)],
        ], ['username.regex' => 'نام کاربری فقط شامل حروف کوچک انگلیسی، عدد، نقطه، خط تیره و زیرخط باشد و با حرف شروع شود.'], ['username' => 'نام کاربری']);

        $old = $user->username;
        $user->username = $data['username'] ?: null;
        $user->save();
        $this->audit->log('account.username_changed', $user->person, ['changes' => ['username' => [$old, $user->username]]], $user);

        return response()->json(['message' => $user->username ? 'نام کاربری ذخیره شد.' : 'نام کاربری حذف شد.', 'username' => $user->username]);
    }

    /** ارسال کد تأیید به شماره جدید */
    public function requestPhoneChange(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $phone = $this->validatedNewPhone($data['phone'], $request);
        $otp->send($phone, 'change_phone', $request->ip());

        return response()->json(['message' => 'کد تأیید به شماره جدید ارسال شد.', 'cooldown' => (int) config('pedigree.otp.resend_cooldown')]);
    }

    /** تأیید شماره جدید و ثبت آن */
    public function confirmPhoneChange(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:10'],
        ]);
        $phone = $this->validatedNewPhone($data['phone'], $request);
        $code = preg_replace('/\D/', '', PersianText::toLatinDigits($data['code']));

        if (! $otp->verify($phone, 'change_phone', (string) $code)) {
            throw ValidationException::withMessages(['code' => 'کد وارد شده نادرست یا منقضی شده است.']);
        }

        $person = $request->user()->person;
        $person->phone = $phone;
        $person->save();
        $this->audit->log('account.phone_changed', $person, [], $request->user());

        return response()->json(['message' => 'شماره موبایل با موفقیت تغییر کرد.', 'phone' => $phone]);
    }

    /** فهرست نشست‌ها و دستگاه‌های واردشده */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        $tokens = $user->tokens()->latest('last_used_at')->get()->map(fn (PersonalAccessToken $t) => [
            'id' => 'token:'.$t->id,
            'type' => 'app',
            'name' => $t->name,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'current' => $current instanceof PersonalAccessToken && $current->id === $t->id,
        ]);

        $web = collect();
        if (config('session.driver') === 'database') {
            $currentSession = $request->hasSession() ? $request->session()->getId() : null;
            $web = DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->orderByDesc('last_activity')
                ->get()
                ->map(fn ($s) => [
                    'id' => 'session:'.hash('sha256', $s->id),
                    'type' => 'web',
                    'name' => $this->browserName((string) $s->user_agent),
                    'ip_address' => $s->ip_address,
                    'last_used_at' => date(DATE_ATOM, (int) $s->last_activity),
                    'current' => $s->id === $currentSession,
                ]);
        }

        return response()->json(['data' => $web->concat($tokens)->values()]);
    }

    /** خروج یک نشست/دستگاه */
    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        [$type, $key] = array_pad(explode(':', $id, 2), 2, null);

        if ($type === 'token') {
            $user->tokens()->where('id', (int) $key)->delete();
        } elseif ($type === 'session' && config('session.driver') === 'database') {
            $sessions = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->get(['id']);
            foreach ($sessions as $s) {
                if (hash_equals(hash('sha256', $s->id), (string) $key)) {
                    DB::table(config('session.table', 'sessions'))->where('id', $s->id)->delete();
                }
            }
        }

        $this->audit->log('account.session_revoked', null, ['type' => $type], $user);

        return response()->json(['message' => 'نشست بسته شد.']);
    }

    /** ثبت توکن پوش‌نوتیفیکیشن اپ موبایل */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'in:android,ios,web'],
            'token' => ['required', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        Device::updateOrCreate(['token' => $data['token']], [
            'user_id' => $request->user()->id,
            'platform' => $data['platform'],
            'app_version' => $data['app_version'] ?? null,
            'last_seen_at' => now(),
        ]);

        return response()->json(['message' => 'ok']);
    }

    public function removeDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        Device::where('token', $data['token'])->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'ok']);
    }

    private function validatedNewPhone(string $input, Request $request): string
    {
        $phone = Phone::normalize($input);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'شماره موبایل معتبر نیست.']);
        }
        $person = $request->user()->person;
        if (! $person) {
            throw new DomainException('حساب شما به پروفایلی متصل نیست.');
        }
        $other = Person::withTrashed()->where('phone_hash', BlindIndex::make($phone, 'phone'))->first();
        if ($other && $other->id !== $person->id) {
            throw ValidationException::withMessages(['phone' => 'این شماره برای شخص دیگری ثبت شده است.']);
        }

        return $phone;
    }

    private function browserName(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'مرورگر',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return trim("{$browser} {$os}");
    }
}
