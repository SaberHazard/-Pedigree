<?php

namespace App\Services;

use App\Models\Marriage;
use App\Models\Person;
use Illuminate\Database\Eloquent\Builder;

/**
 * درجه خویشاوندی (به همان معنای رایج در فارسی):
 *
 *   درجه ۱: پدر، مادر، فرزند، خواهر و برادر، همسر
 *   درجه ۲: پدربزرگ و مادربزرگ، نوه، عمو، عمه، دایی، خاله، برادرزاده و خواهرزاده
 *   درجه ۳: فرزندانِ عمو/عمه/دایی/خاله (عموزاده‌ها ...)، جد، نتیجه، عمو و خالهٔ پدر و مادر
 *   درجه ۴: نوه‌های عمو/عمه/دایی/خاله، فرزندانِ عموزاده‌ها، جدِ پدر ...
 *
 * محاسبه خونی: اگر نزدیک‌ترین جد مشترک u نسل بالاتر از اولی و d نسل بالاتر از دومی باشد:
 *   درجه = u + d − (هر دو بزرگ‌تر از صفر ؟ ۱ : ۰)
 * بستگان سببی (یک ازدواج در مسیر): همسر = ۱؛ بستگانِ همسر و همسرِ بستگان = درجه خونی + ۱
 * (پدرزن، خواهرشوهر، داماد، عروس، زن‌برادر = ۲؛ زن‌عمو و شوهرخاله = ۳).
 *
 * نتیجه برای هر مبدأ یک بار در طول درخواست محاسبه و کش می‌شود.
 */
class KinshipDegrees
{
    /** سطح‌های نمایش: all = همه اعضای خاندان، d4..d1 = بستگان تا درجه، self = فقط خود شخص (و مدیر) */
    public const LEVELS = ['all', 'd4', 'd3', 'd2', 'd1', 'self'];

    public const MAX = 4;

    private const CHUNK = 500;

    /** @var array<string, array<string, array{degree:int, path:string[], inlaw:bool}>> */
    private array $cache = [];

    /** @var array<string, array{0:?string,1:?string}> شناسه ← [پدر، مادر] */
    private array $parents = [];

    /** درجه خویشاوندی دو نفر (null = دورتر از درجه ۴ یا بدون نسبت) */
    public function degree(Person $a, Person $b): ?int
    {
        if ($a->id === $b->id) {
            return 0;
        }

        return $this->from($a)[$b->id]['degree'] ?? null;
    }

    /** بیشینه درجه‌ای که یک سطح نمایش اجازه می‌دهد (null = همه، ۰ = فقط خود) */
    public static function levelMax(?string $level): ?int
    {
        return match ($level) {
            'd4' => 4, 'd3' => 3, 'd2' => 2, 'd1' => 1, 'self' => 0,
            default => null,
        };
    }

    /**
     * همه بستگانِ origin تا درجه max
     *
     * @return array<string, array{degree:int, path:string[], inlaw:bool}> شناسه ← درجه و مسیر (از origin)
     */
    public function from(Person $origin, int $max = self::MAX): array
    {
        $max = max(1, min($max, self::MAX));
        $key = $origin->id.'#'.$max;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $this->parents[$origin->id] ??= [$origin->father_id, $origin->mother_id];

        $result = [];
        $put = function (string $id, int $degree, array $path, bool $inlaw) use (&$result, $origin) {
            if ($id === $origin->id) {
                return;
            }
            $current = $result[$id] ?? null;
            // درجه کمتر بهتر است؛ در درجه برابر، نسبت خونی بر سببی مقدم است
            if ($current === null || $degree < $current['degree'] || ($degree === $current['degree'] && $current['inlaw'] && ! $inlaw)) {
                $result[$id] = ['degree' => $degree, 'path' => $path, 'inlaw' => $inlaw];
            }
        };

        // ۱) بستگان خونی
        $blood = $this->blood($origin->id, $max);
        foreach ($blood as $id => [$degree, $path]) {
            $put($id, $degree, $path, false);
        }

        // ۲) همسرِ خودش و همسرِ بستگان خونی (تا درجه max−۱)
        $near = [$origin->id => [0, [$origin->id]]] + array_filter($blood, fn ($row) => $row[0] < $max);
        $mySpouses = [];
        foreach ($this->spousePairs(array_keys($near)) as [$personId, $spouseId]) {
            [$degree, $path] = $near[$personId];
            if ($personId === $origin->id) {
                $mySpouses[] = $spouseId;
            }
            $put($spouseId, $degree + 1, [...$path, $spouseId], $personId !== $origin->id);
        }

        // ۳) بستگان خونیِ همسر
        if ($max > 1) {
            foreach (array_unique($mySpouses) as $spouseId) {
                foreach ($this->blood($spouseId, $max - 1) as $id => [$degree, $path]) {
                    $put($id, $degree + 1, [$origin->id, ...$path], true);
                }
            }
        }

        return $this->cache[$key] = $result;
    }

    /**
     * بستگان خونی تا درجه max
     *
     * @return array<string, array{0:int, 1:string[]}> شناسه ← [درجه، مسیر از مبدأ]
     */
    private function blood(string $originId, int $max): array
    {
        // بالا رفتن: نیاکان تا max نسل
        $ancestors = [$originId => [0, [$originId]]];
        $frontier = [$originId];
        for ($up = 1; $up <= $max && $frontier; $up++) {
            $this->loadParents($frontier);
            $next = [];
            foreach ($frontier as $id) {
                foreach ($this->parents[$id] ?? [] as $pid) {
                    if ($pid && ! isset($ancestors[$pid])) {
                        $ancestors[$pid] = [$up, [...$ancestors[$id][1], $pid]];
                        $next[] = $pid;
                    }
                }
            }
            $frontier = $next;
        }

        // پایین آمدن از هر جد: [شناسه، نسل بالا، نسل پایین، درجه، مسیر]
        $found = [];
        $frontier = [];
        foreach ($ancestors as $id => [$up, $path]) {
            $found[$id] = [$up, $path];
            $frontier[] = [$id, $up, 0, $up, $path];
        }
        for ($step = 1; $step <= $max && $frontier; $step++) {
            $byParent = [];
            foreach ($frontier as $entry) {
                $byParent[$entry[0]][] = $entry;
            }
            $next = [];
            foreach ($this->childrenOf(array_keys($byParent)) as [$childId, $fatherId, $motherId]) {
                foreach ([$fatherId, $motherId] as $pid) {
                    foreach ($pid ? ($byParent[$pid] ?? []) : [] as [$parentId, $up, $down, $degree, $path]) {
                        // فرزندِ جد (نه خود مبدأ) هم‌درجهٔ جد است: پدربزرگ ۲ ← عمو ۲
                        $childDegree = ($down === 0 && $up > 0) ? $up : $degree + 1;
                        if ($childDegree > $max) {
                            continue;
                        }
                        if (isset($found[$childId]) && $found[$childId][0] <= $childDegree) {
                            continue;
                        }
                        $childPath = [...$path, $childId];
                        $found[$childId] = [$childDegree, $childPath];
                        $next[] = [$childId, $up, $down + 1, $childDegree, $childPath];
                    }
                }
            }
            $frontier = $next;
        }
        unset($found[$originId]);

        return $found;
    }

    private function loadParents(array $ids): void
    {
        $missing = array_values(array_filter($ids, fn ($id) => ! array_key_exists($id, $this->parents)));
        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            foreach (Person::query()->whereIn('id', $chunk)->get(['id', 'father_id', 'mother_id']) as $p) {
                $this->parents[$p->id] = [$p->father_id, $p->mother_id];
            }
            foreach ($chunk as $id) {
                $this->parents[$id] ??= [null, null];
            }
        }
    }

    /** @return array<int, array{0:string,1:?string,2:?string}> [فرزند، پدر، مادر] */
    private function childrenOf(array $ids): array
    {
        $rows = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $children = Person::query()
                ->where(fn (Builder $q) => $q->whereIn('father_id', $chunk)->orWhereIn('mother_id', $chunk))
                ->get(['id', 'father_id', 'mother_id']);
            foreach ($children as $c) {
                $this->parents[$c->id] = [$c->father_id, $c->mother_id];
                $rows[$c->id] = [$c->id, $c->father_id, $c->mother_id];
            }
        }

        return array_values($rows);
    }

    /** @return array<int, array{0:string,1:string}> [شخص، همسرش] */
    private function spousePairs(array $ids): array
    {
        $pairs = [];
        $lookup = array_flip($ids);
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $marriages = Marriage::query()
                ->where(fn (Builder $q) => $q->whereIn('husband_id', $chunk)->orWhereIn('wife_id', $chunk))
                ->get(['husband_id', 'wife_id']);
            foreach ($marriages as $m) {
                if (isset($lookup[$m->husband_id])) {
                    $pairs[$m->husband_id.'>'.$m->wife_id] = [$m->husband_id, $m->wife_id];
                }
                if (isset($lookup[$m->wife_id])) {
                    $pairs[$m->wife_id.'>'.$m->husband_id] = [$m->wife_id, $m->husband_id];
                }
            }
        }

        return array_values($pairs);
    }
}
