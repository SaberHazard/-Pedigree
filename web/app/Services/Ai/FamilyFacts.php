<?php

namespace App\Services\Ai;

use App\Models\Person;
use App\Models\User;
use App\Services\KinshipDegrees;
use App\Services\Tree\RelationshipCalculator;
use App\Support\Jalali;
use App\Support\PersianText;

/**
 * «برگه اطلاعات» خاندان برای مسابقه خاندان با هوش مصنوعی (متنی و گفتگوی صوتی).
 *
 * فقط اطلاعات عمومی که همه اعضا در سایت می‌بینند: نام، نسبت با بازیکن، سال تولد/وفات، محل تولد،
 * شهر، شغل، تحصیلات و لقب. شماره، نشانی، کد ملی، ایمیل و مانند این‌ها هرگز فرستاده نمی‌شوند.
 * مدیر کل می‌تواند این امکان را خاموش کند (pedigree.ai.family_data).
 */
class FamilyFacts
{
    public const MAX_PEOPLE = 40;

    public function __construct(
        private readonly KinshipDegrees $degrees,
        private readonly RelationshipCalculator $relations,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('pedigree.ai.family_data', true);
    }

    public function forUser(User $user): ?string
    {
        $me = $user->person;
        if (! self::enabled() || $me === null) {
            return null;
        }
        $kin = $this->degrees->from($me, 4);
        if (! $kin) {
            return null;
        }
        // هر روز گروه متفاوتی از بستگان (ولی در طول یک روز ثابت، تا گفتگو یکدست بماند)
        $ids = array_keys($kin);
        mt_srand(crc32($user->id.'|'.implode('-', Jalali::today())));
        shuffle($ids);
        mt_srand();
        usort($ids, fn ($a, $b) => $kin[$a]['degree'] <=> $kin[$b]['degree']);
        $people = Person::query()->whereIn('id', array_slice($ids, 0, self::MAX_PEOPLE * 2))->get()->keyBy('id');

        $paths = [];
        foreach ($ids as $id) {
            array_push($paths, ...$kin[$id]['path']);
        }
        $this->relations->preload($paths);

        $levels = (array) config('pedigree.profile.education_levels', []);
        $lines = [];
        foreach ($ids as $id) {
            $p = $people->get($id);
            if ($p === null) {
                continue;
            }
            $bits = [];
            $birth = (int) substr((string) $p->birth_date, 0, 4);
            if ($birth > 0) {
                $bits[] = 'متولد '.$birth.($p->birth_place ? ' در '.$p->birth_place : '');
            } elseif ($p->birth_place) {
                $bits[] = 'زادگاه: '.$p->birth_place;
            }
            if ($p->is_deceased) {
                $death = (int) substr((string) $p->death_date, 0, 4);
                $bits[] = 'درگذشته'.($death > 0 ? ' در '.$death : '');
            } elseif ($p->city) {
                $bits[] = 'ساکن '.$p->city;
            }
            if ($p->occupation) {
                $bits[] = 'شغل: '.$p->occupation;
            }
            if ($p->education_level && isset($levels[$p->education_level])) {
                $bits[] = 'تحصیلات: '.$levels[$p->education_level].($p->education_field ? ' '.$p->education_field : '');
            }
            if ($p->nickname) {
                $bits[] = 'لقب: '.$p->nickname;
            }
            $relation = $this->relations->labelForPath($kin[$id]['path']) ?: 'از بستگان';
            $lines[] = '- '.$p->fullName().' ('.$relation.'، درجه '.$kin[$id]['degree'].')'.($bits ? ': '.implode('، ', $bits) : '');
            if (count($lines) >= self::MAX_PEOPLE) {
                break;
            }
        }
        if (! $lines) {
            return null;
        }

        return PersianText::toPersianDigits('بازیکن: '.$me->fullName()."\nبستگان بازیکن (اطلاعات عمومی شجره‌نامه):\n".implode("\n", $lines));
    }
}
