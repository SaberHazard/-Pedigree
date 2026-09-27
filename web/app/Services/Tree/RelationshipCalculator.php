<?php

namespace App\Services\Tree;

use App\Models\Marriage;
use App\Models\Person;
use Illuminate\Database\Eloquent\Builder;

/**
 * محاسبه نسبت خانوادگی بین دو نفر (مثلاً «پسرعمو»، «خاله»، «مادرزن»).
 *
 * روش: کوتاه‌ترین مسیر در گراف خانواده با یال‌های «والد»، «فرزند» و «همسر»
 * پیدا می‌شود (اولویت با مسیر خونی)، سپس مسیر به گام‌هایی مثل
 * father / brother / wife تبدیل و با جدول اصطلاحات فارسی نام‌گذاری می‌شود.
 * اگر اسم خاصی وجود نداشته باشد، توصیف زنجیره‌ای ساخته می‌شود: «همسرِ برادرِ پدر».
 */
class RelationshipCalculator
{
    private const MAX_DEPTH = 14;

    /** برچسب فارسی هر گام */
    private const STEP_LABELS = [
        'father' => 'پدر', 'mother' => 'مادر',
        'son' => 'پسر', 'daughter' => 'دختر',
        'brother' => 'برادر', 'sister' => 'خواهر',
        'husband' => 'همسر', 'wife' => 'همسر',
    ];

    /** نام‌های خاص برای زنجیره گام‌ها */
    private const NAMED = [
        'father' => 'پدر', 'mother' => 'مادر', 'son' => 'پسر', 'daughter' => 'دختر',
        'brother' => 'برادر', 'sister' => 'خواهر', 'husband' => 'همسر (شوهر)', 'wife' => 'همسر (زن)',

        'father>father' => 'پدربزرگ پدری', 'father>mother' => 'مادربزرگ پدری',
        'mother>father' => 'پدربزرگ مادری', 'mother>mother' => 'مادربزرگ مادری',
        'son>son' => 'نوه (پسرِ پسر)', 'son>daughter' => 'نوه (دخترِ پسر)',
        'daughter>son' => 'نوه (پسرِ دختر)', 'daughter>daughter' => 'نوه (دخترِ دختر)',

        'father>brother' => 'عمو', 'father>sister' => 'عمه',
        'mother>brother' => 'دایی', 'mother>sister' => 'خاله',

        'father>brother>son' => 'پسرعمو', 'father>brother>daughter' => 'دخترعمو',
        'father>sister>son' => 'پسرعمه', 'father>sister>daughter' => 'دخترعمه',
        'mother>brother>son' => 'پسردایی', 'mother>brother>daughter' => 'دختردایی',
        'mother>sister>son' => 'پسرخاله', 'mother>sister>daughter' => 'دخترخاله',

        'brother>son' => 'برادرزاده (پسر)', 'brother>daughter' => 'برادرزاده (دختر)',
        'sister>son' => 'خواهرزاده (پسر)', 'sister>daughter' => 'خواهرزاده (دختر)',

        'husband>father' => 'پدرشوهر', 'husband>mother' => 'مادرشوهر',
        'wife>father' => 'پدرزن', 'wife>mother' => 'مادرزن',
        'son>wife' => 'عروس', 'daughter>husband' => 'داماد',
        'husband>brother' => 'برادرشوهر', 'husband>sister' => 'خواهرشوهر',
        'wife>brother' => 'برادرزن', 'wife>sister' => 'خواهرزن',
        'sister>husband' => 'شوهرخواهر', 'brother>wife' => 'زن‌برادر',
        'wife>sister>husband' => 'باجناق', 'husband>brother>wife' => 'جاری',
        'father>brother>wife' => 'زن‌عمو', 'father>sister>husband' => 'شوهرعمه',
        'mother>brother>wife' => 'زن‌دایی', 'mother>sister>husband' => 'شوهرخاله',
        'father>wife' => 'نامادری', 'mother>husband' => 'ناپدری',
        'wife>son' => 'پسرخوانده (فرزند همسر)', 'wife>daughter' => 'دخترخوانده (فرزند همسر)',
        'husband>son' => 'پسرخوانده (فرزند همسر)', 'husband>daughter' => 'دخترخوانده (فرزند همسر)',
    ];

    /** @var array<string, Person> */
    private array $cache = [];

    /**
     * @return array{found:bool, label?:string, description?:string, path?:array, steps?:array}
     */
    public function calculate(Person $a, Person $b): array
    {
        if ($a->id === $b->id) {
            return ['found' => true, 'label' => 'خود شخص', 'description' => 'خود شخص', 'path' => [$a->id], 'steps' => []];
        }

        $this->cache = [$a->id => $a, $b->id => $b];

        // مسیر خونی از طریق نزدیک‌ترین جد مشترک، و مسیر کلی (شامل ازدواج)؛
        // مسیر خونی انتخاب می‌شود مگر اینکه مسیرِ سببی کوتاه‌تر باشد (مثلاً زن و شوهری که عموزاده‌اند)
        $blood = $this->bloodPath($a, $b);
        $general = $this->shortestPath($a, $b, true);
        $path = match (true) {
            $blood !== null && ($general === null || count($blood) <= count($general)) => $blood,
            default => $general,
        };
        if ($path === null) {
            return ['found' => false];
        }

        $steps = $this->collapseSiblings($this->toSteps($path));
        $keys = array_map(fn ($s) => $s['type'], $steps);
        $signature = implode('>', $keys);

        $description = $this->describe($steps);
        $label = self::NAMED[$signature] ?? $this->generational($keys) ?? $description;

        // خواهر/برادر ناتنی
        if (count($steps) === 1 && in_array($keys[0], ['brother', 'sister'], true) && ($steps[0]['half'] ?? false)) {
            $label .= ' ناتنی';
        }

        return [
            'found' => true,
            'label' => $label,
            'description' => $description,
            'path' => $path,
            'steps' => $keys,
        ];
    }

    /**
     * مسیر خونی از طریق نزدیک‌ترین جد مشترک (بالا رفتن از a، سپس پایین آمدن به b)
     *
     * @return string[]|null
     */
    private function bloodPath(Person $a, Person $b): ?array
    {
        $upA = $this->ancestorsWithPointers($a);
        $upB = $this->ancestorsWithPointers($b);

        $best = null;
        foreach ($upA as $id => [$distA]) {
            if (isset($upB[$id])) {
                $total = $distA + $upB[$id][0];
                if ($best === null || $total < $best[1]) {
                    $best = [$id, $total];
                }
            }
        }
        if ($best === null) {
            return null;
        }

        // a ← ... ← جد مشترک
        $left = [];
        for ($cursor = $best[0]; $cursor !== null; $cursor = $upA[$cursor][1]) {
            $left[] = $cursor;
        }
        $left = array_reverse($left); // از a تا جد مشترک

        // جد مشترک → ... → b
        $right = [];
        for ($cursor = $upB[$best[0]][1]; $cursor !== null; $cursor = $upB[$cursor][1]) {
            $right[] = $cursor;
        }

        return array_merge($left, $right);
    }

    /**
     * نیاکان یک شخص با فاصله و «فرزندِ مسیر» برای بازسازی مسیر
     *
     * @return array<string, array{0:int, 1:?string}> id => [فاصله، شناسه فرزند در مسیر]
     */
    private function ancestorsWithPointers(Person $person): array
    {
        $result = [$person->id => [0, null]];
        $frontier = [$person->id];
        for ($depth = 1; $depth <= self::MAX_DEPTH && $frontier; $depth++) {
            $this->load($frontier);
            $next = [];
            foreach ($frontier as $id) {
                $p = $this->cache[$id] ?? null;
                foreach ([$p?->father_id, $p?->mother_id] as $pid) {
                    if ($pid && ! isset($result[$pid])) {
                        $result[$pid] = [$depth, $id];
                        $next[] = $pid;
                    }
                }
            }
            $frontier = $next;
        }

        return $result;
    }

    /**
     * جستجوی سطح‌به‌سطح (BFS) از a به b.
     *
     * @return string[]|null
     */
    private function shortestPath(Person $a, Person $b, bool $withSpouses): ?array
    {
        $prev = [$a->id => null];
        $frontier = [$a->id];

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier; $depth++) {
            $neighbors = $this->neighbors($frontier, $withSpouses);
            $next = [];
            foreach ($neighbors as [$from, $to]) {
                if (array_key_exists($to, $prev)) {
                    continue;
                }
                $prev[$to] = $from;
                if ($to === $b->id) {
                    $path = [$to];
                    while ($prev[$path[0]] !== null) {
                        array_unshift($path, $prev[$path[0]]);
                    }

                    return $path;
                }
                $next[] = $to;
            }
            $frontier = $next;
        }

        return null;
    }

    /** @return array<int, array{0:string,1:string}> یال‌های [از، به] */
    private function neighbors(array $ids, bool $withSpouses): array
    {
        $edges = [];
        $this->load($ids);

        // والدین
        foreach ($ids as $id) {
            $p = $this->cache[$id] ?? null;
            foreach ([$p?->father_id, $p?->mother_id] as $pid) {
                if ($pid) {
                    $edges[] = [$id, $pid];
                }
            }
        }

        // فرزندان
        $children = Person::query()
            ->where(fn (Builder $q) => $q->whereIn('father_id', $ids)->orWhereIn('mother_id', $ids))
            ->get(['id', 'first_name', 'gender', 'father_id', 'mother_id']);
        foreach ($children as $child) {
            $this->cache[$child->id] ??= $child;
            foreach ([$child->father_id, $child->mother_id] as $pid) {
                if ($pid && in_array($pid, $ids, true)) {
                    $edges[] = [$pid, $child->id];
                }
            }
        }

        if ($withSpouses) {
            $marriages = Marriage::query()
                ->where(fn (Builder $q) => $q->whereIn('husband_id', $ids)->orWhereIn('wife_id', $ids))
                ->get(['husband_id', 'wife_id']);
            foreach ($marriages as $m) {
                if (in_array($m->husband_id, $ids, true)) {
                    $edges[] = [$m->husband_id, $m->wife_id];
                }
                if (in_array($m->wife_id, $ids, true)) {
                    $edges[] = [$m->wife_id, $m->husband_id];
                }
            }
        }

        return $edges;
    }

    private function load(array $ids): void
    {
        $missing = array_values(array_filter($ids, fn ($id) => ! isset($this->cache[$id])));
        if ($missing) {
            foreach (Person::query()->whereIn('id', $missing)->get(['id', 'first_name', 'gender', 'father_id', 'mother_id']) as $p) {
                $this->cache[$p->id] = $p;
            }
        }
    }

    /** تبدیل مسیر به گام‌ها: هر گام نسبتِ نفر بعدی با نفر قبلی است */
    private function toSteps(array $path): array
    {
        $this->load($path);
        $steps = [];
        for ($i = 1; $i < count($path); $i++) {
            $from = $this->cache[$path[$i - 1]];
            $to = $this->cache[$path[$i]];
            $male = $to->gender === Person::MALE;

            if ($from->father_id === $to->id || $from->mother_id === $to->id) {
                $type = $male ? 'father' : 'mother';
            } elseif ($to->father_id === $from->id || $to->mother_id === $from->id) {
                $type = $male ? 'son' : 'daughter';
            } else {
                $type = $male ? 'husband' : 'wife';
            }
            $steps[] = ['type' => $type, 'from' => $from, 'to' => $to];
        }

        return $steps;
    }

    /** «والد ← فرزندِ دیگر» = خواهر/برادر */
    private function collapseSiblings(array $steps): array
    {
        $out = [];
        for ($i = 0; $i < count($steps); $i++) {
            $cur = $steps[$i];
            $next = $steps[$i + 1] ?? null;
            if ($next && in_array($cur['type'], ['father', 'mother'], true) && in_array($next['type'], ['son', 'daughter'], true)
                && $next['to']->id !== $cur['from']->id) {
                $a = $cur['from'];
                $b = $next['to'];
                $half = ! ($a->father_id && $a->father_id === $b->father_id && $a->mother_id && $a->mother_id === $b->mother_id);
                $out[] = ['type' => $next['type'] === 'son' ? 'brother' : 'sister', 'from' => $a, 'to' => $b, 'half' => $half];
                $i++;

                continue;
            }
            $out[] = $cur;
        }

        return $out;
    }

    /** اصطلاحات نسلی: جد، نتیجه، نبیره ... */
    private function generational(array $keys): ?string
    {
        $n = count($keys);
        if ($n < 3) {
            return null;
        }
        $allUp = ! array_diff($keys, ['father', 'mother']);
        $allDown = ! array_diff($keys, ['son', 'daughter']);
        $last = end($keys);

        if ($allUp) {
            $base = in_array($last, ['father'], true) ? 'جد' : 'جده';
            $side = $keys[0] === 'father' ? 'پدری' : 'مادری';

            return $n === 3 ? "{$base} ({$side})" : "{$base} ({$side}، {$n} نسل بالاتر)";
        }
        if ($allDown) {
            return match ($n) {
                3 => 'نتیجه',
                4 => 'نبیره',
                default => "نوادهٔ نسل {$n}",
            };
        }

        return null;
    }

    /** توصیف زنجیره‌ای: «همسرِ برادرِ پدر» */
    private function describe(array $steps): string
    {
        $labels = array_map(fn ($s) => self::STEP_LABELS[$s['type']], array_reverse($steps));

        return implode('ِ ', $labels);
    }
}
