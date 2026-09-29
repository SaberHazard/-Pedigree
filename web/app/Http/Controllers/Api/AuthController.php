<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PersonRequest;
use App\Http\Resources\UserResource;
use App\Models\Person;
use App\Models\User;
use App\Notifications\MemberPending;
use App\Rules\NationalCodeRule;
use App\Rules\PartialDateRule;
use App\Services\AuditLogger;
use App\Services\Auth\CaptchaService;
use App\Services\Auth\OtpService;
use App\Services\People\PersonService;
use App\Support\BlindIndex;
use App\Support\NationalCode;
use App\Support\PersianText;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ورود و ثبت‌نام.
 *
 * روش‌های ورود:
 *  ۱. موبایل + کد پیامکی (OTP) - بدون نیاز به نام کاربری و رمز
 *  ۲. کد ملی یا نام کاربری یا موبایل + رمز عبور (برای کسی که به شماره‌اش دسترسی ندارد؛
 *     مثلاً سالمندی که فقط نام کاربری و رمزی دارد که مدیر یا فرزندانش برایش تعیین کرده‌اند)
 *
 * خروجی ورود:
 *  - برای سایت (درخواست از همان دامنه): سشن امن کوکی‌محور (httpOnly)
 *  - برای اپ موبایل (ارسال device_name): توکن Bearer
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly CaptchaService $captcha,
        private readonly AuditLogger $audit,
    ) {}

    public function captcha(): JsonResponse
    {
        return response()->json($this->captcha->generate());
    }

    /** مرحله ۱: درخواست کد پیامکی */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'captcha_id' => ['nullable', 'string', 'max:64'],
            'captcha_answer' => ['nullable', 'string', 'max:10'],
        ]);

        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'شماره موبایل معتبر نیست.']);
        }

        // پس از چند درخواست از یک IP، کپچا اجباری می‌شود (جلوگیری از اسپم پیامکی)
        $ipKey = 'otp-ip:'.$request->ip();
        if (RateLimiter::attempts($ipKey) >= (int) config('pedigree.otp.captcha_after', 3)) {
            $answer = isset($data['captcha_answer']) ? PersianText::toLatinDigits($data['captcha_answer']) : null;
            if (! $this->captcha->verify($data['captcha_id'] ?? null, $answer)) {
                return response()->json([
                    'message' => 'لطفاً عدد داخل تصویر را وارد کنید.',
                    'captcha_required' => true,
                    'captcha' => $this->captcha->generate(),
                ], 422);
            }
        }
        RateLimiter::hit($ipKey, 3600);

        $person = Person::findByPhone($phone);
        // عضو «در انتظار تأیید» هم می‌تواند وارد شود (فقط صفحه انتظار را می‌بیند)
        $canLogin = $person && ! $person->is_deceased && (! $person->user || ! $person->user->isBlocked());
        $canRegister = ! $person && config('pedigree.registration.enabled');

        $code = null;
        if ($canLogin || $canRegister) {
            $code = $this->otp->send($phone, 'login', $request->ip());
        }

        $response = [
            // پیام یکسان برای شماره‌های ثبت‌شده و نشده (جلوگیری از کشف شماره‌ها)
            'message' => 'در صورت ثبت بودن شماره، کد تأیید برای شما پیامک شد.',
            'phone' => $phone,
            'cooldown' => (int) config('pedigree.otp.resend_cooldown', 60),
            'length' => (int) config('pedigree.otp.length', 6),
            'ttl' => (int) config('pedigree.otp.ttl', 120),
        ];

        // فقط در محیط توسعه با درایور log، کد برای راحتی تست برگردانده می‌شود
        if ($code && config('app.debug') && config('pedigree.sms.driver') === 'log' && ! app()->isProduction()) {
            $response['debug_code'] = $code;
        }

        return response()->json($response);
    }

    /** مرحله ۲: بررسی کد و ورود (یا شروع ثبت‌نام) */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:10'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $phone = Phone::normalize($data['phone']);
        $code = preg_replace('/\D/', '', PersianText::toLatinDigits($data['code']));

        if (! $phone || ! $this->otp->verify($phone, 'login', (string) $code)) {
            $this->audit->log('auth.otp_failed', null, ['phone' => Phone::mask($phone)]);
            throw ValidationException::withMessages(['code' => 'کد وارد شده نادرست یا منقضی شده است.']);
        }

        $person = Person::findByPhone($phone);

        if (! $person) {
            if (! config('pedigree.registration.enabled')) {
                throw new DomainException('این شماره در سیستم ثبت نشده است. از یکی از بستگان بخواهید شماره شما را در پروفایلتان ثبت کند.', 404);
            }
            $token = Str::random(48);
            Cache::put('register:'.hash('sha256', $token), $phone, now()->addMinutes(20));

            return response()->json(['registration_required' => true, 'registration_token' => $token]);
        }

        if ($person->is_deceased) {
            throw new DomainException('این پروفایل متعلق به شخص درگذشته است و امکان ورود با آن وجود ندارد.', 403);
        }

        $user = $person->user ?? User::create(['person_id' => $person->id, 'role' => User::ROLE_MEMBER, 'status' => User::STATUS_ACTIVE]);
        if ($user->isBlocked()) {
            throw new DomainException('حساب کاربری شما مسدود شده است.', 403);
        }

        $user->otp_verified_at = now();
        $this->secureClaim($user);

        return $this->completeLogin($request, $user, 'otp');
    }

    /** تکمیل ثبت‌نام شماره جدید */
    public function register(Request $request, PersonService $persons): JsonResponse
    {
        if (is_string($request->input('national_code'))) {
            $request->merge(['national_code' => NationalCode::normalize($request->input('national_code')) ?? $request->input('national_code')]);
        }
        // کد ملی اجباری است مگر کسی که کد ملی ایرانی ندارد (در صورت فعال بودن این گزینه)
        $withoutCode = $request->boolean('no_national_code') && config('pedigree.registration.allow_without_national_code', true);
        $codeRequired = config('pedigree.registration.require_national_code', true) && ! $withoutCode;
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'in:m,f'],
            'national_code' => [$codeRequired ? 'required' : 'nullable', 'string', new NationalCodeRule],
            'no_national_code' => ['sometimes', 'boolean'],
            'birth_date' => ['nullable', new PartialDateRule],
            'password' => ['nullable', 'string', 'max:100', Password::min((int) config('pedigree.password.min_length', 8))->letters()->numbers()],
            'device_name' => ['nullable', 'string', 'max:100'],
            // معرفی برای مدیر (مثلاً «پسر حسن احمدی از شاخه شیراز»)؛ وقتی تأیید عضویت لازم است
            'join_note' => [config('pedigree.registration.require_approval', true) ? 'required' : 'nullable', 'string', 'min:5', 'max:300'],
        ]);

        $nationalCode = $withoutCode ? null : ($data['national_code'] ?? null);
        $tokenKey = 'register:'.hash('sha256', $data['registration_token']);
        if ($nationalCode && Person::withTrashed()->where('national_code_hash', BlindIndex::make($nationalCode, 'national_code'))->exists()) {
            // جلوگیری از استفاده از یک توکن ثبت‌نام برای «امتحان کردن» کدهای ملی دیگران
            $tries = Cache::increment($tokenKey.':conflicts');
            if ($tries >= 3) {
                Cache::forget($tokenKey);
            }
            // ادعای پروفایل موجود فقط از راه ثبت موبایل توسط بستگان (کد ملی راز نیست و نباید برای تصاحب کافی باشد)
            throw ValidationException::withMessages(['national_code' => 'پروفایلی با این کد ملی از قبل در شجره‌نامه هست. از یکی از بستگان (یا مدیر) بخواهید شماره موبایل شما را در همان پروفایل ثبت کند، سپس با پیامک وارد شوید.']);
        }

        $phone = Cache::pull($tokenKey);
        if (! $phone) {
            throw new DomainException('مهلت ثبت‌نام به پایان رسیده است؛ لطفاً دوباره کد دریافت کنید.');
        }
        if (Person::withTrashed()->where('phone_hash', BlindIndex::make($phone, 'phone'))->exists()) {
            throw new DomainException('این شماره قبلاً ثبت شده است.');
        }

        $user = DB::transaction(function () use ($data, $phone, $persons, $nationalCode) {
            // اولین کاربر فقط در صورت فعال بودن تنظیم، مدیر کل می‌شود (پیشنهاد: از pedigree:install استفاده کنید)
            $firstAdmin = config('pedigree.registration.first_user_is_admin') && User::count() === 0;
            // غریبه‌ها تا تأیید مدیر هیچ‌چیز از شجره‌نامه نمی‌بینند
            $pending = ! $firstAdmin && config('pedigree.registration.require_approval', true);
            $user = User::create([
                'role' => $firstAdmin ? User::ROLE_SUPER_ADMIN : User::ROLE_MEMBER,
                'status' => $pending ? User::STATUS_PENDING : User::STATUS_ACTIVE,
            ]);
            $user->join_note = isset($data['join_note']) ? PersianText::normalize(trim(preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $data['join_note']) ?? '')) : null;
            $person = $persons->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'gender' => $data['gender'],
                'birth_date' => $data['birth_date'] ?? null,
                'phone' => $phone,
                'national_code' => $nationalCode,
            ], $user);
            $user->person_id = $person->id;
            if (! empty($data['password'])) {
                $user->password = $data['password'];
                $user->password_changed_at = now();
                $user->password_set_by = $user->id;
            }
            $user->otp_verified_at = now();
            $user->save();

            return $user;
        });

        $this->audit->log('auth.registered', $user->person, ['pending' => $user->isPending()], $user);
        if ($user->isPending()) {
            Notification::send(User::query()->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->where('status', User::STATUS_ACTIVE)->get(), new MemberPending($user));
        }

        return $this->completeLogin($request, $user, 'register');
    }

    /**
     * ورود با رمز عبور.
     * شناسه ورود می‌تواند کد ملی، نام کاربری یا شماره موبایل باشد
     * (فیلد قدیمی national_code هم برای سازگاری پذیرفته می‌شود).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required_without_all:national_code,username', 'nullable', 'string', 'max:50'],
            'national_code' => ['nullable', 'string', 'max:20'],
            'username' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        [$kind, $value] = $this->parseIdentifier((string) ($data['identifier'] ?? $data['national_code'] ?? $data['username'] ?? ''));
        $maxAttempts = (int) config('pedigree.password.max_attempts', 5);
        $lockout = (int) config('pedigree.password.lockout_seconds', 900);
        // دو قفل: یکی برای (شناسه + IP) و یکی سراسری برای شناسه (حمله از چند IP)
        $idHash = hash('sha256', $kind.':'.$value);
        $keys = [
            'login:'.$idHash.'|'.$request->ip() => $maxAttempts,
            'login-code:'.$idHash => $maxAttempts * 4,
        ];
        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw new DomainException("به دلیل تلاش‌های ناموفق زیاد، ورود با این شناسه تا {$minutes} دقیقه دیگر ممکن نیست.", 429);
            }
        }

        $user = match ($kind) {
            'national_code' => Person::findByNationalCode($value)?->user,
            'phone' => Person::findByPhone($value)?->user,
            'username' => $value !== '' ? User::where('username', $value)->first() : null,
            default => null,
        };
        $person = $user?->person;

        // در صورت نبود کاربر هم هش بررسی می‌شود تا زمان پاسخ یکسان باشد
        $hash = $user?->password ?? Cache::rememberForever('auth:dummy-hash', fn () => Hash::make(Str::random(32)));
        $valid = Hash::check($data['password'], $hash) && $user?->password !== null && $person !== null;

        if (! $valid) {
            foreach (array_keys($keys) as $key) {
                RateLimiter::hit($key, $lockout);
            }
            $this->audit->log('auth.password_failed', $person, ['via' => $kind], $user);
            throw ValidationException::withMessages(['password' => 'شناسه ورود (کد ملی / نام کاربری / موبایل) یا رمز عبور نادرست است.']);
        }
        if ($person->is_deceased) {
            throw new DomainException('این پروفایل متعلق به شخص درگذشته است.', 403);
        }
        if ($user->isBlocked()) {
            throw new DomainException('حساب کاربری شما مسدود شده است.', 403);
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::clear($key);
        }

        return $this->completeLogin($request, $user, $kind === 'username' ? 'username' : 'password');
    }

    /** تشخیص نوع شناسه ورود: کد ملی (۱۰ رقم معتبر)، موبایل، یا نام کاربری */
    private function parseIdentifier(string $raw): array
    {
        $raw = trim($raw);
        $digits = preg_replace('/[\s\-]/', '', PersianText::toLatinDigits($raw)) ?? '';
        if (ctype_digit($digits) && NationalCode::isValid(NationalCode::normalize($digits) ?? '')) {
            return ['national_code', NationalCode::normalize($digits)];
        }
        if (preg_match('/^\+?\d{10,14}$/', $digits) && ($phone = Phone::normalize($digits))) {
            return ['phone', $phone];
        }

        return ['username', PersonRequest::normalizeUsername($raw)];
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }
        $this->audit->log('auth.logout', null, [], $user);

        return response()->json(['message' => 'از حساب خارج شدید.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('person'));
    }

    /**
     * صاحب واقعی شماره وارد شده است: اگر رمز عبور حساب را شخص دیگری تعیین کرده
     * (مثلاً والدین یا کسی که پروفایل را ساخته)، آن رمز حذف و همه نشست‌های قبلی بسته می‌شوند
     * تا هیچ‌کس جز صاحب شماره به حساب دسترسی نداشته باشد.
     */
    private function secureClaim(User $user): void
    {
        if ($user->password === null || $user->password_set_by === null || $user->password_set_by === $user->id) {
            return;
        }

        $user->password = null;
        $user->password_set_by = null;
        $user->tokens()->delete();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        $this->audit->log('auth.claimed', $user->person, ['password_cleared' => true], $user);
    }

    /** ورود نهایی: سشن برای سایت، توکن برای اپ */
    private function completeLogin(Request $request, User $user, string $method): JsonResponse
    {
        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        $this->audit->log('auth.login', $user->person, ['method' => $method], $user);

        $wantsToken = $request->filled('device_name') || ! EnsureFrontendRequestsAreStateful::fromFrontend($request);

        if ($wantsToken) {
            $days = (int) config('pedigree.token_ttl_days', 90);
            $token = $user->createToken(
                mb_substr((string) ($request->input('device_name') ?: 'mobile'), 0, 100),
                ['*'],
                now()->addDays($days),
            );

            return response()->json([
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => now()->addDays($days)->toIso8601String(),
                'user' => new UserResource($user->load('person')),
            ]);
        }

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        return response()->json(['user' => new UserResource($user->load('person'))]);
    }
}
