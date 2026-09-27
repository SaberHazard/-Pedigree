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
        }

        $done = count(array_filter($checks));

        return [
            'percent' => (int) round($done * 100 / count($checks)),
            'missing' => array_keys(array_filter($checks, fn ($ok) => ! $ok)),
        ];
    }
}
