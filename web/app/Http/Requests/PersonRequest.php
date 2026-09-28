<?php

namespace App\Http\Requests;

use App\Models\Person;
use App\Models\User;
use App\Rules\NationalCodeRule;
use App\Rules\PartialDateRule;
use App\Rules\PhoneRule;
use App\Services\KinshipDegrees;
use App\Support\BlindIndex;
use App\Support\Countries;
use App\Support\NationalCode;
use App\Support\PartialDate;
use App\Support\PersianText;
use App\Support\Phone;
use App\Support\SocialNetworks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * اعتبارسنجی فرم ساخت/ویرایش شخص.
 *
 * قوانین در متد استاتیک personRules هم قابل استفاده‌اند تا فرم «افزودن
 * بستگان» همین قوانین را دوباره تعریف نکند.
 */
class PersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        // دسترسی در کنترلر با Policy بررسی می‌شود
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::normalizeInput($this->all()));
    }

    public function rules(): array
    {
        $person = $this->route('person');

        return self::personRules($person instanceof Person ? $person : null);
    }

    public function after(): array
    {
        return [fn (Validator $v) => self::afterValidation($v, $this->all(), $this->route('person'))];
    }

    public function attributes(): array
    {
        return self::attributeNames();
    }

    /** یکدست‌سازی اعداد فارسی، موبایل، کد ملی و تاریخ‌ها قبل از اعتبارسنجی */
    public static function normalizeInput(array $input): array
    {
        $out = [];
        if (isset($input['national_code']) && is_string($input['national_code']) && trim($input['national_code']) !== '') {
            $out['national_code'] = NationalCode::normalize($input['national_code']);
        }
        if (isset($input['phone']) && is_string($input['phone']) && trim($input['phone']) !== '') {
            $out['phone'] = Phone::normalize($input['phone']) ?? $input['phone'];
        }
        foreach (['birth_date', 'death_date'] as $field) {
            if (isset($input[$field]) && is_string($input[$field]) && trim($input[$field]) !== '') {
                $out[$field] = PartialDate::normalize($input[$field]) ?? $input[$field];
            }
        }
        if (isset($input['birth_cert_no']) && is_string($input['birth_cert_no'])) {
            $out['birth_cert_no'] = trim(PersianText::toLatinDigits($input['birth_cert_no']));
        }
        if (isset($input['birth_order']) && is_string($input['birth_order'])) {
            $out['birth_order'] = PersianText::toLatinDigits($input['birth_order']);
        }
        // اعداد (مختصات، کد پستی، تلفن ثابت) با ارقام لاتین ذخیره می‌شوند
        foreach (['home_lat', 'home_lng', 'burial_lat', 'burial_lng', 'postal_code', 'landline'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $out[$field] = trim(PersianText::toLatinDigits($input[$field]));
            }
        }
        if (isset($input['country']) && is_string($input['country'])) {
            $out['country'] = strtoupper(trim($input['country']));
        }
        if (isset($input['username']) && is_string($input['username'])) {
            $out['username'] = self::normalizeUsername($input['username']);
        }
        if (isset($input['website']) && is_string($input['website']) && trim($input['website']) !== '' && ! preg_match('#^https?://#i', trim($input['website']))) {
            $out['website'] = 'https://'.trim($input['website']);
        }
        // شبکه‌های اجتماعی: لینک کامل، @شناسه یا شماره ← شکل استاندارد (نامعتبرها برای خطای اعتبارسنجی دست‌نخورده می‌مانند)
        if (isset($input['social']) && is_array($input['social'])) {
            $social = [];
            foreach ($input['social'] as $network => $value) {
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                $social[$network] = is_string($network) && is_string($value)
                    ? (SocialNetworks::normalize($network, $value) ?? trim($value))
                    : $value;
            }
            $out['social'] = $social ?: null;
        }
        if (isset($input['custom_fields']) && is_array($input['custom_fields'])) {
            $rows = [];
            foreach ($input['custom_fields'] as $row) {
                if (is_array($row) && trim((string) ($row['label'] ?? '')) !== '' && trim((string) ($row['value'] ?? '')) !== '') {
                    $rows[] = ['label' => trim((string) $row['label']), 'value' => trim((string) $row['value'])];
                }
            }
            $out['custom_fields'] = $rows ?: null;
        }
        // رشته خالی = null
        foreach ($input as $key => $value) {
            if ($value === '' && ! array_key_exists($key, $out)) {
                $out[$key] = null;
            }
        }

        return $out;
    }

    public static function personRules(?Person $person = null, bool $genderRequired = true): array
    {
        $creating = $person === null;
        $req = $creating ? 'required' : 'sometimes';

        return [
            'first_name' => [$req, 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'nickname' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:50'],
            'gender' => [$creating && $genderRequired ? 'required' : 'sometimes', 'in:m,f'],
            'birth_order' => ['nullable', 'integer', 'min:1', 'max:60'],
            'birth_date' => ['nullable', new PartialDateRule],
            'birth_place' => ['nullable', 'string', 'max:150'],
            'is_deceased' => ['sometimes', 'boolean'],
            'death_date' => ['nullable', new PartialDateRule],
            'death_place' => ['nullable', 'string', 'max:150'],
            'burial_place' => ['nullable', 'string', 'max:200'],
            'national_code' => ['nullable', 'string', new NationalCodeRule],
            'phone' => ['nullable', 'string', new PhoneRule],
            'birth_cert_no' => ['nullable', 'string', 'max:30'],
            'birth_cert_place' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:191'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'education' => ['nullable', 'string', 'max:150'],
            'residence' => ['nullable', 'string', 'max:200'],
            'biography' => ['nullable', 'string', 'max:'.(int) config('pedigree.profile.texts.biography.max', 60000)],
            'is_locked' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'max:100', Password::min((int) config('pedigree.password.min_length', 8))->letters()->numbers()],
            'username' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9._-]*$/', 'min:'.(int) config('pedigree.username.min', 3), 'max:'.(int) config('pedigree.username.max', 30), Rule::notIn(config('pedigree.username.reserved', []))],

            // تحصیلات و شغل
            'education_level' => ['nullable', Rule::in(array_keys(config('pedigree.profile.education_levels', [])))],
            'education_field' => ['nullable', 'string', 'max:150'],
            'education_field_group' => ['nullable', Rule::in(array_keys(config('pedigree.profile.education_field_groups', [])))],
            'honorific_mode' => ['sometimes', 'in:auto,none'],
            'education_institution' => ['nullable', 'string', 'max:150'],
            'academic_rank' => ['nullable', Rule::in(array_keys(config('pedigree.profile.academic_ranks', [])))],
            'workplace' => ['nullable', 'string', 'max:150'],

            // محل سکونت
            'country' => ['nullable', 'string', 'size:2', fn ($attr, $value, $fail) => Countries::isValid($value) ? null : $fail('کشور انتخاب‌شده معتبر نیست.')],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z \-]+$/'],
            'home_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:home_lng'],
            'home_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:home_lat'],
            'location_visibility' => ['sometimes', Rule::in(KinshipDegrees::LEVELS)],
            'burial_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:burial_lng'],
            'burial_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:burial_lat'],

            // راه‌های ارتباطی
            'landline' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-() ]+$/'],
            'website' => ['nullable', 'string', 'max:191', 'url:http,https'],
            'social' => ['nullable', 'array:'.implode(',', array_keys(config('pedigree.profile.social_networks', [])))],
            'social.*' => ['nullable', 'string', 'max:191', function (string $attribute, mixed $value, \Closure $fail) {
                $network = substr($attribute, 7);
                if (is_string($value) && SocialNetworks::normalize($network, $value) !== $value) {
                    $label = SocialNetworks::all()[$network]['label'] ?? $network;
                    $fail(($network === 'whatsapp')
                        ? "شماره {$label} معتبر نیست (مثلاً ۰۹۱۲۱۲۳۴۵۶۷ یا +۴۹۱۵۱...)."
                        : "شناسه {$label} معتبر نیست؛ شناسه (مثلاً @ali.rezaei) یا لینک صفحه را وارد کنید.");
                }
            }],
            'contact_visibility' => ['sometimes', Rule::in(KinshipDegrees::LEVELS)],
            'accept_greeting_sms' => ['sometimes', 'boolean'],

            // ویژگی‌های دیگر
            'blood_type' => ['nullable', Rule::in(config('pedigree.profile.blood_types', []))],
            'languages' => ['nullable', 'string', 'max:200'],
            'interests' => ['nullable', 'string', 'max:500'],
            'custom_fields' => ['nullable', 'array', 'max:'.(int) config('pedigree.profile.max_attributes', 40)],
            'custom_fields.*.label' => ['required', 'string', 'max:60'],
            'custom_fields.*.value' => ['required', 'string', 'max:300'],
        ];
    }

    /** نام کاربری: حروف کوچک لاتین، بدون فاصله */
    public static function normalizeUsername(string $value): string
    {
        return strtolower(trim(PersianText::toLatinDigits($value)));
    }

    /** بررسی‌های ترکیبی: یکتایی کد ملی/موبایل و ترتیب تاریخ‌ها */
    public static function afterValidation(Validator $v, array $data, mixed $person): void
    {
        $currentId = $person instanceof Person ? $person->id : null;

        if (! empty($data['national_code']) && NationalCode::isValid($data['national_code'])) {
            $other = Person::withTrashed()->where('national_code_hash', BlindIndex::make($data['national_code'], 'national_code'))->first();
            if ($other && $other->id !== $currentId) {
                $v->errors()->add('national_code', 'این کد ملی قبلاً برای شخص دیگری («'.$other->fullName().'») ثبت شده است.');
            }
        }
        if (! empty($data['phone']) && Phone::normalize($data['phone'])) {
            $other = Person::withTrashed()->where('phone_hash', BlindIndex::make($data['phone'], 'phone'))->first();
            if ($other && $other->id !== $currentId) {
                $v->errors()->add('phone', 'این شماره موبایل قبلاً برای شخص دیگری ثبت شده است.');
            }
        }

        if (! empty($data['username'])) {
            $taken = User::where('username', $data['username'])
                ->when($currentId, fn ($q) => $q->where(fn ($q) => $q->whereNull('person_id')->orWhere('person_id', '!=', $currentId)))
                ->exists();
            if ($taken) {
                $v->errors()->add('username', 'این نام کاربری قبلاً انتخاب شده است.');
            }
        }

        // کد پستی ایران ۱۰ رقم است
        if (($data['country'] ?? null) === 'IR' && ! empty($data['postal_code']) && ! preg_match('/^\d{10}$/', str_replace([' ', '-'], '', $data['postal_code']))) {
            $v->errors()->add('postal_code', 'کد پستی ایران باید ۱۰ رقم باشد.');
        }

        $birth = PartialDate::normalize($data['birth_date'] ?? null);
        $death = PartialDate::normalize($data['death_date'] ?? null);
        // فقط بخش مشترک دو تاریخ مقایسه می‌شود (مثلاً وقتی فقط سال فوت معلوم است)
        $common = $birth && $death ? min(strlen($birth), strlen($death)) : 0;
        if ($common > 0 && strcmp(substr($death, 0, $common), substr($birth, 0, $common)) < 0) {
            $v->errors()->add('death_date', 'تاریخ فوت نمی‌تواند قبل از تاریخ تولد باشد.');
        }
    }

    public static function attributeNames(): array
    {
        return [
            'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'nickname' => 'شهرت/لقب', 'title' => 'عنوان',
            'gender' => 'جنسیت', 'birth_order' => 'ترتیب تولد', 'birth_date' => 'تولد', 'birth_place' => 'محل تولد',
            'death_date' => 'فوت', 'death_place' => 'محل فوت', 'burial_place' => 'محل دفن',
            'national_code' => 'کد ملی', 'phone' => 'موبایل', 'birth_cert_no' => 'شماره شناسنامه',
            'birth_cert_place' => 'محل صدور', 'email' => 'ایمیل', 'occupation' => 'شغل', 'education' => 'تحصیلات',
            'residence' => 'محله / منطقه', 'biography' => 'زندگی‌نامه', 'password' => 'رمز عبور', 'username' => 'نام کاربری',
            'education_level' => 'مقطع تحصیلی', 'education_field' => 'رشته تحصیلی', 'education_institution' => 'دانشگاه / مدرسه',
            'academic_rank' => 'مرتبه علمی', 'workplace' => 'محل کار', 'country' => 'کشور', 'province' => 'استان',
            'city' => 'شهر', 'address' => 'نشانی', 'postal_code' => 'کد پستی', 'home_lat' => 'عرض جغرافیایی',
            'home_lng' => 'طول جغرافیایی', 'burial_lat' => 'عرض جغرافیایی مزار', 'burial_lng' => 'طول جغرافیایی مزار',
            'landline' => 'تلفن ثابت', 'website' => 'وب‌سایت', 'social' => 'شبکه‌های اجتماعی', 'blood_type' => 'گروه خونی',
            'education_field_group' => 'گروه رشته', 'honorific_mode' => 'عنوان خودکار',
            'contact_visibility' => 'نمایش شماره و راه‌های ارتباطی', 'location_visibility' => 'نمایش نشانی',
            'accept_greeting_sms' => 'دریافت پیامک تبریک',
            'languages' => 'زبان‌ها', 'interests' => 'علاقه‌مندی‌ها', 'custom_fields' => 'ویژگی‌های دیگر',
            'custom_fields.*.label' => 'عنوان ویژگی', 'custom_fields.*.value' => 'مقدار ویژگی',
        ];
    }
}
