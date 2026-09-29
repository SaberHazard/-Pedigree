<?php

namespace App\Services\People;

use App\Models\Person;
use App\Models\User;
use App\Services\Kinship;
use App\Support\AttributedText;

/**
 * اطلاعات کمکی پروفایل: نویسندگان (برای رنگ‌بندی و راهنمای رنگ) و درصد تکمیل.
 */
class ProfileService
{
    public function __construct(private readonly Kinship $kinship) {}

    /** شناسه همه کسانی که در این پروفایل چیزی نوشته‌اند (فیلدها، متن‌ها، رزومه) */
    public function authorIds(Person $person): array
    {
        $ids = array_column($person->field_meta ?? [], 'u');
        foreach ($person->texts()->get() as $text) {
            array_push($ids, ...AttributedText::authors($text->cleanSegments()));
        }
        foreach ($person->resumeItems()->get(['created_by', 'updated_by']) as $item) {
            $ids[] = $item->updated_by ?? $item->created_by;
        }

        return $ids;
    }

    /**
     * نویسندگان پروفایل به همراه «رنگ» هر کدام:
     *  - owner : خود شخص (رنگ اصلی سایت)
     *  - admin : مدیری که نسبت نزدیکی با شخص ندارد (مشکی)
     *  - عدد   : بقیه به ترتیب، هر کدام یک رنگ از پالت
     *
     * @param  int[]  $userIds
     * @return array<int, array{id: int, name: string, person_id: ?string, relation: ?string, color: string|int}>
     */
    public function contributors(Person $person, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, fn ($id) => $id !== null)));
        if (! $userIds) {
            return [];
        }
        sort($userIds);

        $users = User::with('person')->whereIn('id', $userIds)->get()->keyBy('id');
        $out = [];
        $palette = 0;
        foreach ($userIds as $id) {
            $user = $users->get($id);
            if (! $user) {
                $out[] = ['id' => $id, 'name' => 'کاربر حذف‌شده', 'person_id' => null, 'relation' => null, 'color' => $palette++];

                continue;
            }
            $relation = $user->person ? $this->kinship->directRelation($user->person, $person) : null;
            $color = match (true) {
                $relation === 'self' => 'owner',
                $relation === null && $user->isAdmin() => 'admin',
                default => $palette++,
            };
            $out[] = [
                'id' => $user->id,
                'name' => $user->displayName(),
                'person_id' => $user->person_id,
                'relation' => $this->relationLabel($relation, $user->person?->gender),
                'color' => $color,
            ];
        }

        return $out;
    }

    /** برچسب فارسی نسبت نویسنده با صاحب پروفایل */
    public function relationLabel(?string $relation, ?string $gender): ?string
    {
        $female = $gender === Person::FEMALE;

        return match ($relation) {
            'self' => 'خود شخص',
            'parent' => $female ? 'مادر' : 'پدر',
            'child' => $female ? 'دختر' : 'پسر',
            'sibling' => $female ? 'خواهر' : 'برادر',
            'spouse' => 'همسر',
            default => null,
        };
    }

    /** برچسب فارسی بخش‌های «درصد تکمیل» (برای پیام‌های سرور) */
    public const COMPLETENESS_LABELS = [
        'avatar' => 'عکس پروفایل', 'birth_date' => 'تاریخ تولد', 'birth_place' => 'محل تولد', 'education_level' => 'تحصیلات',
        'occupation' => 'شغل', 'location' => 'محل زندگی', 'summary' => 'بیوگرافی', 'biography' => 'زندگی‌نامه کامل',
        'resume' => 'رزومه', 'father' => 'پدر', 'mother' => 'مادر', 'death_date' => 'تاریخ وفات', 'burial_place' => 'آرامگاه',
        'contact' => 'راه ارتباطی', 'social' => 'شبکه‌های اجتماعی',
    ];

    /**
     * بخش‌هایی از پروفایل که مدیر کل می‌تواند برای «مجاز بودن پیامک از پنل سایت» الزامی کند
     * (پنل مدیریت ← تنظیمات ← پیامک تبریک اعضا؛ هر بخش یک تیک)
     */
    public const SMS_CHECK_LABELS = [
        'avatar' => 'عکس پروفایل (تأییدشده)',
        'birth_date' => 'تاریخ تولد',
        'birth_place' => 'محل تولد',
        'national_code' => 'کد ملی',
        'mobile' => 'شماره موبایل',
        'email' => 'ایمیل',
        'nickname' => 'شهرت / لقب',
        'education_level' => 'مقطع تحصیلی',
        'education_field' => 'رشته تحصیلی',
        'occupation' => 'شغل یا محل کار',
        'location' => 'شهر یا کشور محل زندگی',
        'home' => 'نشانی یا موقعیت خانه روی نقشه',
        'father' => 'پدر (در درخت)',
        'mother' => 'مادر (در درخت)',
        'summary' => 'بیوگرافی',
        'biography' => 'زندگی‌نامه کامل',
        'resume' => 'رزومه',
        'contact' => 'یک راه ارتباطی (موبایل، ایمیل یا تلفن ثابت)',
        'social' => 'شبکه‌های اجتماعی',
        'blood_type' => 'گروه خونی',
        'languages' => 'زبان‌ها',
        'interests' => 'علاقه‌مندی‌ها',
        'custom_fields' => 'ویژگی‌های دلخواه (مثل غذای محبوب)',
    ];

    /** پیش‌فرض: همان بخش‌های «درصد تکمیل» پروفایل یک عضو زنده */
    public const SMS_DEFAULT_CHECKS = [
        'avatar', 'birth_date', 'birth_place', 'education_level', 'occupation', 'location',
        'summary', 'biography', 'resume', 'father', 'mother', 'contact', 'social',
    ];

    /** بخش‌هایی که مدیر برای پیامک الزامی کرده است (فقط کلیدهای معتبر؛ خالی = پیش‌فرض) */
    public static function smsRequiredChecks(): array
    {
        $keys = array_values(array_intersect((array) config('pedigree.member_sms.required_fields', self::SMS_DEFAULT_CHECKS), array_keys(self::SMS_CHECK_LABELS)));

        return $keys ?: self::SMS_DEFAULT_CHECKS;
    }

    /**
     * درصد تکمیل برای پیامک از پنل سایت: فقط از روی بخش‌هایی که مدیر کل تیک زده است
     *
     * @return array{percent:int, missing:string[], checks:string[]}
     */
    public function smsCompleteness(Person $person): array
    {
        $keys = self::smsRequiredChecks();
        $texts = array_intersect($keys, ['summary', 'biography', 'resume'])
            ? $person->texts()->get()->filter(fn ($t) => trim((string) $t->plain) !== '')->pluck('field')->all()
            : [];
        $filled = fn ($v) => $v !== null && $v !== '' && $v !== [];
        $all = [
            'avatar' => fn () => (bool) $person->avatar?->isApproved(),
            'birth_date' => fn () => (bool) $person->birth_date,
            'birth_place' => fn () => $filled($person->birth_place),
            'national_code' => fn () => (bool) $person->national_code_hash,
            'mobile' => fn () => (bool) $person->phone_hash,
            'email' => fn () => $filled($person->email),
            'nickname' => fn () => $filled($person->nickname),
            'education_level' => fn () => $filled($person->education_level),
            'education_field' => fn () => $filled($person->education_field),
            'occupation' => fn () => $filled($person->occupation) || $filled($person->workplace),
            'location' => fn () => $filled($person->city) || $filled($person->country),
            'home' => fn () => $filled($person->address) || $person->home_lat !== null,
            'father' => fn () => (bool) $person->father_id,
            'mother' => fn () => (bool) $person->mother_id,
            'summary' => fn () => in_array('summary', $texts, true),
            'biography' => fn () => in_array('biography', $texts, true),
            'resume' => fn () => in_array('resume', $texts, true) || $person->resumeItems()->exists(),
            'contact' => fn () => (bool) ($person->phone_hash || $person->email || $person->landline),
            'social' => fn () => ! empty($person->social),
            'blood_type' => fn () => $filled($person->blood_type),
            'languages' => fn () => $filled($person->languages),
            'interests' => fn () => $filled($person->interests),
            'custom_fields' => fn () => ! empty($person->custom_fields),
        ];
        $missing = [];
        foreach ($keys as $key) {
            if (! $all[$key]()) {
                $missing[] = $key;
            }
        }

        return [
            'percent' => (int) floor((count($keys) - count($missing)) * 100 / count($keys)),
            'missing' => $missing,
            'checks' => $keys,
        ];
    }

    /** درصد تکمیل یک پروفایل (همه داده لازم خودش بارگذاری می‌شود) */
    public function completenessOf(Person $person): array
    {
        $texts = $person->texts()->get()->filter(fn ($t) => trim((string) $t->plain) !== '')->pluck('field')->all();
        $avatar = $person->avatar;

        return $this->completeness($person, $texts, $person->resumeItems()->count(), $avatar !== null && $avatar->isApproved());
    }

    /**
     * درصد تکمیل پروفایل و فهرست بخش‌های خالی (برای تشویق به تکمیل)
     *
     * @param  string[]  $filledTexts  متن‌های بلند پرشده (summary, biography ...)
     */
    public function completeness(Person $person, array $filledTexts, int $resumeCount, bool $hasAvatar): array
    {
        $checks = [
            'avatar' => $hasAvatar,
            'birth_date' => (bool) $person->birth_date,
            'birth_place' => (bool) $person->birth_place,
            'education_level' => (bool) $person->education_level,
            'occupation' => (bool) ($person->occupation || $person->workplace),
            'location' => (bool) ($person->city || $person->country),
            'summary' => in_array('summary', $filledTexts, true),
            'biography' => in_array('biography', $filledTexts, true),
            'resume' => $resumeCount > 0 || in_array('resume', $filledTexts, true),
            'father' => (bool) $person->father_id,
            'mother' => (bool) $person->mother_id,
        ];
        if ($person->is_deceased) {
            $checks['death_date'] = (bool) $person->death_date;
            $checks['burial_place'] = (bool) ($person->burial_place || $person->burial_lat !== null);
        } else {
            $checks['contact'] = (bool) ($person->phone_hash || $person->email || $person->landline);
            $checks['social'] = ! empty($person->social);
        }

        $done = count(array_filter($checks));

        return [
            'percent' => (int) round($done * 100 / count($checks)),
            'missing' => array_keys(array_filter($checks, fn ($ok) => ! $ok)),
        ];
    }
}
