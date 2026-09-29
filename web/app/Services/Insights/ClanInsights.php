<?php

namespace App\Services\Insights;

use App\Models\Marriage;
use App\Models\Person;
use App\Models\User;
use App\Support\HistoricalEvents;
use App\Support\Jalali;
use App\Support\PersianText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * بینش‌های خاندان (هم‌تراز ابزارهای MyHeritage و Ancestry):
 *
 *  - بررسی ناسازگاری داده‌ها: تاریخ ناممکن یا بعید، والد خیلی جوان یا پیر، تولد پس از فوت مادر،
 *    ازدواج پیش از تولد، خواهر و برادر با فاصله ناممکن، شخص احتمالاً تکراری و ...
 *  - آمار خاندان: جمعیت، نسل‌ها، میانگین عمر، اندازه خانواده در هر نسل، نام‌ها و شهرهای پرتکرار، رکوردها
 *  - خط زمان زندگی هر شخص: رویدادهای خانوادگی و تاریخی، با سن شخص در آن زمان
 *
 * فقط اطلاعات عمومی شجره‌نامه (همان که همه اعضا می‌بینند) به کار می‌رود و هرگز شماره، نشانی یا کد ملی.
 * نتیجه‌های سنگین چند دقیقه کش می‌شوند تا درخواست‌های پیاپی بار سرور نشوند.
 */
final class ClanInsights
{
    public const MAX_ISSUES = 500;

    private const CACHE_SECONDS = 600;

    private const COLUMNS = [
        'id', 'first_name', 'last_name', 'title', 'gender', 'father_id', 'mother_id', 'birth_date', 'death_date',
        'is_deceased', 'birth_place', 'death_place', 'city', 'occupation', 'education_level', 'education_field',
        'education_field_group', 'academic_rank', 'honorific_mode', 'updated_at',
    ];

    /** نوع ناسازگاری‌ها (برای فیلتر در صفحه) */
    public const CODES = [
        'future_date' => 'تاریخ در آینده',
        'death_before_birth' => 'وفات پیش از تولد',
        'death_not_marked' => 'تاریخ وفات بدون علامت درگذشته',
        'too_long_life' => 'عمر بیش از ۱۱۵ سال',
        'too_old_living' => 'بیش از ۱۱۰ سال و زنده',
        'parent_gender' => 'جنسیت والد',
        'parent_born_after_child' => 'والد پس از فرزند متولد شده',
        'parent_too_young' => 'والد خیلی جوان',
        'parent_too_old' => 'والد خیلی مسن',
        'born_after_mother_death' => 'تولد پس از فوت مادر',
        'born_after_father_death' => 'تولد مدت‌ها پس از فوت پدر',
        'siblings_too_close' => 'فاصله تولد خواهر و برادر',
        'marriage_before_birth' => 'ازدواج پیش از تولد',
        'married_too_young' => 'ازدواج در سن خیلی کم',
        'marriage_after_death' => 'ازدواج پس از فوت',
        'divorce_before_marriage' => 'جدایی پیش از ازدواج',
        'possible_duplicate' => 'شخص احتمالاً تکراری',
    ];

    // ------------------------------------------------------------------ بررسی ناسازگاری

    /** @return array{issues: array, total: int, counts: array, by_code: array, checked: int, generated_at: string} */
    public function consistency(): array
    {
        return Cache::remember('insights:consistency:'.$this->fingerprint(), self::CACHE_SECONDS, fn () => $this->computeConsistency());
    }

    private function computeConsistency(): array
    {
        $people = $this->people();
        $today = Jalali::today();
        $issues = [];
        $add = function (string $code, string $level, Person $p, string $message, ?Person $other = null) use (&$issues) {
            $issues[] = [
                'code' => $code,
                'level' => $level,
                'person' => $this->ref($p),
                'other' => $other ? $this->ref($other) : null,
                'message' => PersianText::toPersianDigits($message),
            ];
        };

        foreach ($people as $p) {
            $birth = self::date($p->birth_date);
            $death = self::date($p->death_date);
            if (($birth && self::before($today, $birth)) || ($death && self::before($today, $death))) {
                $add('future_date', 'error', $p, 'تاریخ '.($birth && self::before($today, $birth) ? 'تولد' : 'وفات').' در آینده است.');
            }
            if ($birth && $death && self::before($death, $birth)) {
                $add('death_before_birth', 'error', $p, 'تاریخ وفات پیش از تاریخ تولد ثبت شده است.');
            } elseif ($birth && $death && self::age($birth, $death)[0] > 115) {
                $add('too_long_life', 'warning', $p, 'عمر بیش از ۱۱۵ سال ثبت شده است؛ تاریخ‌ها را بررسی کنید.');
            }
            if ($death && ! $p->is_deceased) {
                $add('death_not_marked', 'warning', $p, 'تاریخ وفات دارد ولی «درگذشته» علامت نخورده است.');
            }
            if ($birth && ! $death && ! $p->is_deceased && self::age($birth, $today)[0] > 110) {
                $add('too_old_living', 'warning', $p, 'بیش از ۱۱۰ سال سن دارد و درگذشته ثبت نشده؛ شاید درگذشته باشد.');
            }

            foreach (['father' => Person::MALE, 'mother' => Person::FEMALE] as $role => $gender) {
                $parent = $people->get($p->{$role.'_id'});
                if ($parent === null) {
                    continue;
                }
                $label = $role === 'father' ? 'پدر' : 'مادر';
                if ($parent->gender !== $gender) {
                    $add('parent_gender', 'error', $p, "جنسیت کسی که {$label} ثبت شده با این نقش جور نیست.", $parent);
                }
                $parentBirth = self::date($parent->birth_date);
                if ($birth && $parentBirth) {
                    [$min, $max] = self::age($parentBirth, $birth);
                    if (self::before($birth, $parentBirth)) {
                        $add('parent_born_after_child', 'error', $p, "{$label} پس از فرزندش به دنیا آمده است.", $parent);
                    } elseif ($max < 13) {
                        $add('parent_too_young', 'warning', $p, "{$label} هنگام تولد این فرزند حداکثر {$max} ساله بوده است.", $parent);
                    } elseif ($min > ($role === 'father' ? 75 : 55)) {
                        $add('parent_too_old', 'warning', $p, "{$label} هنگام تولد این فرزند دست‌کم {$min} ساله بوده است.", $parent);
                    }
                }
                $parentDeath = self::date($parent->death_date);
                if ($birth && $parentDeath) {
                    if ($role === 'mother' && self::before($parentDeath, $birth)) {
                        $add('born_after_mother_death', 'error', $p, 'تولد پس از درگذشت مادر ثبت شده است.', $parent);
                    }
                    if ($role === 'father' && self::monthsAfter($parentDeath, $birth) > 10) {
                        $add('born_after_father_death', 'error', $p, 'تولد بیش از ۱۰ ماه پس از درگذشت پدر ثبت شده است.', $parent);
                    }
                }
            }
        }

        $this->siblingIssues($people, $add);
        $this->duplicateIssues($people, $add);
        $this->marriageIssues($people, $add);

        // خطاها اول
        usort($issues, fn ($a, $b) => ($a['level'] === 'error' ? 0 : 1) <=> ($b['level'] === 'error' ? 0 : 1));
        $byCode = array_count_values(array_column($issues, 'code'));
        $counts = array_count_values(array_column($issues, 'level')) + ['error' => 0, 'warning' => 0];

        return [
            'issues' => array_slice($issues, 0, self::MAX_ISSUES),
            'total' => count($issues),
            'counts' => ['error' => $counts['error'], 'warning' => $counts['warning']],
            'by_code' => $byCode,
            'checked' => $people->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** خواهر و برادرهای هم‌مادر با تاریخ کامل که کمتر از ۸ ماه فاصله دارند (دوقلوها مستثنا) */
    private function siblingIssues(Collection $people, callable $add): void
    {
        $byMother = [];
        foreach ($people as $p) {
            $days = ($d = self::date($p->birth_date)) ? self::days($d) : null;
            if ($p->mother_id && $days !== null) {
                $byMother[$p->mother_id][] = [$days, $p];
            }
        }
        foreach ($byMother as $children) {
            usort($children, fn ($a, $b) => $a[0] <=> $b[0]);
            for ($i = 1; $i < count($children); $i++) {
                $gap = $children[$i][0] - $children[$i - 1][0];
                if ($gap > 2 && $gap < 240) {
                    $add('siblings_too_close', 'warning', $children[$i][1], 'فاصله تولد با خواهر/برادرش فقط '.$gap.' روز است.', $children[$i - 1][1]);
                }
            }
        }
    }

    /** هم‌نام با پدر یا مادر یکسان: احتمالاً یک نفر دو بار ثبت شده است */
    private function duplicateIssues(Collection $people, callable $add): void
    {
        $groups = [];
        foreach ($people as $p) {
            $name = PersianText::searchable(trim($p->first_name.' '.$p->last_name));
            if ($name === '') {
                continue;
            }
            foreach (['father_id', 'mother_id'] as $parent) {
                if ($p->{$parent}) {
                    $groups[$name.'|'.$p->{$parent}][] = $p;
                }
            }
        }
        $reported = [];
        foreach ($groups as $group) {
            for ($i = 1; $i < count($group); $i++) {
                $pair = $group[0]->id.'|'.$group[$i]->id;
                if (isset($reported[$pair])) {
                    continue;
                }
                $reported[$pair] = true;
                $add('possible_duplicate', 'warning', $group[$i], 'هم‌نام با خواهر/برادری از همان والد است؛ شاید یک نفر دو بار ثبت شده باشد.', $group[0]);
            }
        }
    }

    private function marriageIssues(Collection $people, callable $add): void
    {
        $marriages = Marriage::query()->get(['id', 'husband_id', 'wife_id', 'status', 'marriage_date', 'end_date']);
        foreach ($marriages as $m) {
            $married = self::date($m->marriage_date);
            $ended = self::date($m->end_date);
            $husband = $people->get($m->husband_id);
            $wife = $people->get($m->wife_id);
            if ($married && $ended && self::before($ended, $married) && $husband) {
                $add('divorce_before_marriage', 'error', $husband, 'تاریخ جدایی یا فوت همسر پیش از تاریخ ازدواج است.', $wife);
            }
            if (! $married) {
                continue;
            }
            foreach ([[$husband, $wife], [$wife, $husband]] as [$spouse, $other]) {
                if ($spouse === null) {
                    continue;
                }
                $birth = self::date($spouse->birth_date);
                $death = self::date($spouse->death_date);
                if ($birth && self::before($married, $birth)) {
                    $add('marriage_before_birth', 'error', $spouse, 'تاریخ ازدواج پیش از تولد ثبت شده است.', $other);
                } elseif ($birth && self::age($birth, $married)[1] < 13) {
                    $add('married_too_young', 'warning', $spouse, 'هنگام ازدواج حداکثر '.self::age($birth, $married)[1].' ساله بوده است.', $other);
                }
                if ($death && self::before($death, $married)) {
                    $add('marriage_after_death', 'error', $spouse, 'تاریخ ازدواج پس از درگذشت ثبت شده است.', $other);
                }
            }
        }
    }

    // ------------------------------------------------------------------ آمار خاندان

    public function stats(): array
    {
        return Cache::remember('insights:stats:'.$this->fingerprint(), self::CACHE_SECONDS, fn () => $this->computeStats());
    }

    private function computeStats(): array
    {
        $people = $this->people();
        $today = Jalali::today();
        $deceased = $people->where('is_deceased', true);
        $living = $people->where('is_deceased', false);

        // میانگین عمر درگذشتگان
        $ages = [Person::MALE => [], Person::FEMALE => []];
        $longest = null;
        foreach ($deceased as $p) {
            $birth = self::date($p->birth_date);
            $death = self::date($p->death_date);
            if (! $birth || ! $death || self::before($death, $birth)) {
                continue;
            }
            [$min, $max] = self::age($birth, $death);
            $age = ($min + $max) / 2;
            if ($age > 120) {
                continue;
            }
            $ages[$p->gender][] = $age;
            if ($longest === null || $min > $longest[1]) {
                $longest = [$p, $min];
            }
        }
        $avg = fn (array $list) => $list ? round(array_sum($list) / count($list), 1) : null;

        // تولد در هر دهه و هر ماه
        $decades = [];
        $months = array_fill(1, 12, 0);
        $oldest = null;
        foreach ($people as $p) {
            $birth = self::date($p->birth_date);
            if (! $birth || $birth[0] < 1100 || self::before($today, $birth)) {
                continue;
            }
            $decade = intdiv($birth[0], 10) * 10;
            $decades[$decade] = ($decades[$decade] ?? 0) + 1;
            if ($birth[1] !== null) {
                $months[$birth[1]]++;
            }
            if (! $p->is_deceased && ! $p->death_date && self::age($birth, $today)[0] <= 110) {
                $key = [$birth[0], $birth[1] ?? 6, $birth[2] ?? 15];
                if ($oldest === null || $key < $oldest[1]) {
                    $oldest = [$p, $key, self::age($birth, $today)[0]];
                }
            }
        }
        ksort($decades);

        // فرزندان، نوه‌ها و اندازه خانواده در هر نسل (بر اساس دهه تولد مادر)
        $children = [];
        foreach ($people as $p) {
            foreach ([$p->father_id, $p->mother_id] as $parent) {
                if ($parent && $people->has($parent)) {
                    $children[$parent][] = $p->id;
                }
            }
        }
        $mostChildren = null;
        $mostGrand = null;
        $bySize = [];
        foreach ($children as $parentId => $kids) {
            $count = count($kids);
            if ($mostChildren === null || $count > $mostChildren[1]) {
                $mostChildren = [$people->get($parentId), $count];
            }
            $grand = 0;
            foreach ($kids as $kid) {
                $grand += count($children[$kid] ?? []);
            }
            if ($grand > 0 && ($mostGrand === null || $grand > $mostGrand[1])) {
                $mostGrand = [$people->get($parentId), $grand];
            }
            $parent = $people->get($parentId);
            $birth = self::date($parent->birth_date);
            if ($parent->gender === Person::FEMALE && $birth && $birth[0] >= 1200 && self::age($birth, $today)[0] >= 45) {
                $decade = intdiv($birth[0], 10) * 10;
                $bySize[$decade][] = $count;
            }
        }
        ksort($bySize);

        $levels = (array) config('pedigree.profile.education_levels', []);
        $education = [];
        foreach ($levels as $key => $label) {
            $n = $people->where('education_level', $key)->count();
            if ($n > 0) {
                $education[] = ['label' => $label, 'count' => $n];
            }
        }

        return [
            'totals' => [
                'persons' => $people->count(),
                'living' => $living->count(),
                'deceased' => $deceased->count(),
                'male' => $people->where('gender', Person::MALE)->count(),
                'female' => $people->where('gender', Person::FEMALE)->count(),
                'marriages' => Marriage::query()->count(),
                'divorces' => Marriage::query()->where('status', 'divorced')->count(),
                'members' => User::query()->where('status', User::STATUS_ACTIVE)->whereNotNull('person_id')->count(),
                'generations' => $this->generations($people),
            ],
            'lifespan' => [
                'all' => $avg(array_merge($ages[Person::MALE], $ages[Person::FEMALE])),
                'male' => $avg($ages[Person::MALE]),
                'female' => $avg($ages[Person::FEMALE]),
                'count' => count($ages[Person::MALE]) + count($ages[Person::FEMALE]),
            ],
            'births_by_decade' => array_map(fn ($d, $n) => ['decade' => $d, 'count' => $n], array_keys($decades), $decades),
            'birth_months' => array_map(fn ($m, $n) => ['month' => $m, 'label' => Jalali::MONTHS[$m], 'count' => $n], array_keys($months), $months),
            'family_size' => array_map(fn ($d, $sizes) => ['decade' => $d, 'avg' => round(array_sum($sizes) / count($sizes), 1), 'mothers' => count($sizes)], array_keys($bySize), $bySize),
            'names' => [
                'male' => self::top($people->where('gender', Person::MALE)->pluck('first_name'), 10),
                'female' => self::top($people->where('gender', Person::FEMALE)->pluck('first_name'), 10),
                'last' => self::top($people->pluck('last_name'), 10),
            ],
            'places' => [
                'birth' => self::top($people->pluck('birth_place'), 8),
                'city' => self::top($living->pluck('city'), 8),
            ],
            'occupations' => self::top($people->pluck('occupation'), 10),
            'education' => $education,
            'records' => [
                'oldest_living' => $oldest ? ['person' => $this->ref($oldest[0]), 'value' => $oldest[2]] : null,
                'longest_lived' => $longest ? ['person' => $this->ref($longest[0]), 'value' => $longest[1]] : null,
                'most_children' => $mostChildren ? ['person' => $this->ref($mostChildren[0]), 'value' => $mostChildren[1]] : null,
                'most_grandchildren' => $mostGrand ? ['person' => $this->ref($mostGrand[0]), 'value' => $mostGrand[1]] : null,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** بیشترین تعداد نسل پشت سر هم (بدون بازگشت عمیق و با محافظت در برابر حلقه) */
    private function generations(Collection $people): int
    {
        $depth = [];
        foreach ($people->keys() as $start) {
            if (isset($depth[$start])) {
                continue;
            }
            $stack = [$start];
            $onStack = [$start => true];
            while ($stack) {
                $current = $people->get(end($stack));
                $next = null;
                foreach ([$current->father_id, $current->mother_id] as $parent) {
                    if ($parent && $people->has($parent) && ! isset($depth[$parent]) && ! isset($onStack[$parent])) {
                        $next = $parent;
                        break;
                    }
                }
                if ($next !== null) {
                    $stack[] = $next;
                    $onStack[$next] = true;

                    continue;
                }
                $id = array_pop($stack);
                unset($onStack[$id]);
                $d = 1;
                foreach ([$current->father_id, $current->mother_id] as $parent) {
                    if ($parent && isset($depth[$parent])) {
                        $d = max($d, $depth[$parent] + 1);
                    }
                }
                $depth[$id] = $d;
            }
        }

        return $depth ? max($depth) : 0;
    }

    // ------------------------------------------------------------------ خط زمان زندگی

    public function timeline(Person $person): array
    {
        $birth = self::date($person->birth_date);
        $death = self::date($person->death_date);
        $today = Jalali::today();
        $events = [];
        $push = function (?array $date, string $kind, string $title, ?Person $who = null, ?string $category = null) use (&$events, $birth) {
            if ($date === null || ($birth && $kind !== 'birth' && self::before($date, $birth))) {
                return;
            }
            $age = null;
            $approx = false;
            if ($birth) {
                [$min, $max] = self::age($birth, $date);
                $age = max(0, $max);
                $approx = $min !== $max && $min >= 0;
            }
            $events[] = [
                'date' => self::format($date),
                'year' => $date[0],
                'kind' => $kind,
                'title' => PersianText::toPersianDigits($title),
                'person' => $who ? $this->ref($who) : null,
                'age' => $age,
                'approx' => $approx,
                'category' => $category,
                '_sort' => [$date[0], $date[1] ?? 0, $date[2] ?? 0, $kind === 'birth' ? 0 : ($kind === 'death' ? 2 : 1)],
            ];
        };
        $gendered = fn (Person $p, string $male, string $female) => $p->gender === Person::FEMALE ? $female : $male;

        $push($birth, 'birth', 'تولد'.($person->birth_place ? ' در '.$person->birth_place : ''));

        $parents = Person::query()->whereIn('id', array_filter([$person->father_id, $person->mother_id]))->get(self::COLUMNS);
        foreach ($parents as $parent) {
            $push(self::date($parent->death_date), 'parent_death', ($parent->id === $person->father_id ? 'درگذشت پدر' : 'درگذشت مادر').': '.$parent->fullName(), $parent);
        }

        foreach ($person->siblingsQuery()->limit(40)->get(self::COLUMNS) as $sibling) {
            $push(self::date($sibling->birth_date), 'sibling', $gendered($sibling, 'تولد برادر', 'تولد خواهر').': '.$sibling->fullName(), $sibling);
            $push(self::date($sibling->death_date), 'sibling_death', $gendered($sibling, 'درگذشت برادر', 'درگذشت خواهر').': '.$sibling->fullName(), $sibling);
        }

        $marriages = $person->marriages()->get();
        $spouses = Person::query()->whereIn('id', $marriages->map(fn (Marriage $m) => $m->husband_id === $person->id ? $m->wife_id : $m->husband_id))->get(self::COLUMNS)->keyBy('id');
        foreach ($marriages as $m) {
            $spouse = $spouses->get($m->husband_id === $person->id ? $m->wife_id : $m->husband_id);
            if ($spouse === null) {
                continue;
            }
            $push(self::date($m->marriage_date), 'marriage', 'ازدواج با '.$spouse->fullName(), $spouse);
            if ($m->status === 'divorced') {
                $push(self::date($m->end_date), 'divorce', 'جدایی از '.$spouse->fullName(), $spouse);
            }
            $push(self::date($spouse->death_date), 'spouse_death', 'درگذشت همسر: '.$spouse->fullName(), $spouse);
        }

        $children = $person->childrenQuery()->limit(60)->get(self::COLUMNS);
        foreach ($children as $child) {
            $push(self::date($child->birth_date), 'child', $gendered($child, 'تولد پسر', 'تولد دختر').': '.$child->fullName(), $child);
            $push(self::date($child->death_date), 'child_death', $gendered($child, 'درگذشت پسر', 'درگذشت دختر').': '.$child->fullName(), $child);
        }
        if ($children->isNotEmpty()) {
            $ids = $children->pluck('id')->all();
            $childMarriages = Marriage::query()->where(fn ($q) => $q->whereIn('husband_id', $ids)->orWhereIn('wife_id', $ids))->limit(100)->get();
            $byId = $children->keyBy('id');
            foreach ($childMarriages as $m) {
                $child = $byId->get($m->husband_id) ?? $byId->get($m->wife_id);
                $push(self::date($m->marriage_date), 'child_marriage', $gendered($child, 'ازدواج پسر', 'ازدواج دختر').': '.$child->fullName(), $child);
            }
            $grandchildren = Person::query()->where(fn ($q) => $q->whereIn('father_id', $ids)->orWhereIn('mother_id', $ids))->limit(150)->get(self::COLUMNS);
            foreach ($grandchildren as $grandchild) {
                $push(self::date($grandchild->birth_date), 'grandchild', 'تولد نوه: '.$grandchild->fullName(), $grandchild);
            }
        }

        $push($death, 'death', 'درگذشت'.($person->death_place ? ' در '.$person->death_place : ''));

        // رویدادهای تاریخی در طول زندگی
        if ($birth) {
            $end = $death ? $death[0] : ($person->is_deceased ? $birth[0] : $today[0]);
            foreach (HistoricalEvents::between($birth[0], $end) as [$y, $m, $d, $title, $category]) {
                $date = [$y, $m, $d];
                if ($death && self::before($death, $date)) {
                    continue;
                }
                $push($date, 'history', $title, null, $category);
            }
        }

        usort($events, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);
        $events = array_map(function ($e) {
            unset($e['_sort']);

            return $e;
        }, array_slice($events, 0, 400));

        return [
            'person' => $this->ref($person),
            'has_birth' => $birth !== null,
            'events' => $events,
            'categories' => HistoricalEvents::CATEGORIES,
        ];
    }

    // ------------------------------------------------------------------ کمکی‌ها

    /** @return Collection<string, Person> */
    private function people(): Collection
    {
        return Person::query()->get(self::COLUMNS)->keyBy('id');
    }

    /** با هر تغییر در اشخاص یا ازدواج‌ها نتیجه کش‌شده کنار گذاشته می‌شود */
    private function fingerprint(): string
    {
        return md5(implode('|', [
            Person::withTrashed()->count(), Person::withTrashed()->max('updated_at'),
            Marriage::query()->count(), Marriage::query()->max('updated_at'),
            implode('-', Jalali::today()),
        ]));
    }

    private function ref(Person $p): array
    {
        return ['id' => $p->id, 'name' => $p->fullName(), 'gender' => $p->gender, 'is_deceased' => (bool) $p->is_deceased];
    }

    /** @return array<int, array{name: string, count: int}> */
    private static function top(Collection $values, int $limit): array
    {
        $counts = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_map(fn ($name, $count) => ['name' => (string) $name, 'count' => $count], array_keys(array_slice($counts, 0, $limit, true)), array_slice($counts, 0, $limit, true));
    }

    /** «1305» / «1305-07» / «1305-07-12» ← [سال، ماه|null، روز|null] */
    public static function date(?string $value): ?array
    {
        if (! $value || ! preg_match('/^(\d{3,4})(?:-(\d{1,2})(?:-(\d{1,2}))?)?$/', $value, $m) || (int) $m[1] < 1) {
            return null;
        }

        return [(int) $m[1], isset($m[2]) ? (int) $m[2] : null, isset($m[3]) ? (int) $m[3] : null];
    }

    /** a قطعاً پیش از b است؟ (اگر با دقت موجود معلوم نیست، خیر) */
    public static function before(array $a, array $b): bool
    {
        if ($a[0] !== $b[0]) {
            return $a[0] < $b[0];
        }
        if ($a[1] === null || $b[1] === null) {
            return false;
        }
        if ($a[1] !== $b[1]) {
            return $a[1] < $b[1];
        }
        if ($a[2] === null || $b[2] === null) {
            return false;
        }

        return $a[2] < $b[2];
    }

    /**
     * سن در یک تاریخ با دقت موجود: [کمینه، بیشینه]
     *
     * @return array{0:int, 1:int}
     */
    public static function age(array $birth, array $at): array
    {
        $years = $at[0] - $birth[0];
        if ($birth[1] === null || $at[1] === null) {
            return [$years - 1, $years];
        }
        if ($at[1] !== $birth[1]) {
            return $at[1] < $birth[1] ? [$years - 1, $years - 1] : [$years, $years];
        }
        if ($birth[2] === null || $at[2] === null) {
            return [$years - 1, $years];
        }

        return $at[2] < $birth[2] ? [$years - 1, $years - 1] : [$years, $years];
    }

    /** چند ماه b پس از a است (با دقت موجود؛ کمینه) */
    private static function monthsAfter(array $a, array $b): int
    {
        if ($a[1] === null || $b[1] === null) {
            return ($b[0] - $a[0] - 1) * 12;
        }

        return ($b[0] - $a[0]) * 12 + ($b[1] - $a[1]) - 1;
    }

    /** شماره روز (برای تاریخ کامل) */
    private static function days(array $d): ?int
    {
        if ($d[1] === null || $d[2] === null || ! Jalali::isValid($d[0], $d[1], $d[2])) {
            return null;
        }
        [$gy, $gm, $gd] = Jalali::toGregorian($d[0], $d[1], $d[2]);

        return intdiv((int) gmmktime(0, 0, 0, $gm, $gd, $gy), 86400);
    }

    private static function format(array $d): string
    {
        return sprintf('%04d', $d[0]).($d[1] !== null ? sprintf('-%02d', $d[1]).($d[2] !== null ? sprintf('-%02d', $d[2]) : '') : '');
    }
}
