<?php

namespace App\Http\Requests;

use App\Models\Person;
use App\Rules\NationalCodeRule;
use App\Rules\PartialDateRule;
use App\Rules\PhoneRule;
use App\Support\BlindIndex;
use App\Support\NationalCode;
use App\Support\PartialDate;
use App\Support\PersianText;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
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
            'biography' => ['nullable', 'string', 'max:20000'],
            'is_locked' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'max:100', Password::min((int) config('pedigree.password.min_length', 8))->letters()->numbers()],
        ];
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
            'residence' => 'محل سکونت', 'biography' => 'زندگی‌نامه', 'password' => 'رمز عبور',
        ];
    }
}
