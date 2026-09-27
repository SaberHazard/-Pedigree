<?php

namespace App\Services\Tree;

use App\Models\Marriage;
use App\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * استخراج داده‌های درخت از پایگاه داده.
 *
 * چهار حالت نمایش:
 *  - descendants : نوادگان یک شخص (از بالا به پایین) + همسرانشان
 *  - ancestors   : نیاکان یک شخص (پدر، پدربزرگ ... تا بالاترین جد ثبت‌شده)
 *  - hourglass   : ترکیب هر دو (ساعت شنی)
 *  - lineage     : مسیر نسبی بین دو نفر (مثلاً از جد اعلا تا من)
 *
 * خروجی همه حالت‌ها یک قالب دارد:
 *  { focus, persons: [...], marriages: [...], meta: {...} }
 * و چیدمان گرافیکی در سمت کاربر (مرورگر یا اپ) انجام می‌شود.
 * پیمایش سطح به سطح با whereIn انجام می‌شود (هر نسل یک کوئری) که روی
 * MySQL، MariaDB، PostgreSQL و SQLite یکسان و سریع کار می‌کند.
 */
class TreeService
{
    /** @var array<string, Person> */
    private array $persons = [];

    private bool $truncated = false;

    private int $maxNodes;

    public function __construct()
    {
        $this->maxNodes = (int) config('pedigree.tree.max_nodes', 5000);
    }

    public function descendants(Person $root, int $depth): array
    {
        $this->reset();
        $this->add($root);
        $blood = $this->collectDescendants([$root->id], $depth, $expandedAll);
        $this->addSpousesAndCoParents($blood);

        return $this->result($root, 'descendants', [
            'depth' => $depth,
            'expandable' => $expandedAll,
        ]);
    }

    public function ancestors(Person $root, int $depth): array
    {
        $this->reset();
        $this->add($root);
        $expandable = $this->collectAncestors($root, $depth);

        return $this->result($root, 'ancestors', [
            'depth' => $depth,
            'expandable' => $expandable,
        ]);
    }

    public function hourglass(Person $root, int $up, int $down): array
    {
        $this->reset();
        $this->add($root);
        $upExpandable = $this->collectAncestors($root, $up);
        $blood = $this->collectDescendants([$root->id], $down, $downExpandable);
        $this->addSpousesAndCoParents($blood);

        return $this->result($root, 'hourglass', [
            'up' => $up,
            'down' => $down,
            'expandable' => array_values(array_unique(array_merge($upExpandable, $downExpandable))),
        ]);
    }

    /**
     * مسیر نسبی بین دو نفر. اگر مسیری نباشد null برمی‌گرداند.
     * خروجی شامل path (از بالاترین نفر تا پایین‌ترین) است.
     */
    public function lineage(Person $from, Person $to, int $down = 0): ?array
    {
        $path = $this->findPath($from, $to) ?? $this->findPath($to, $from);
        if ($path === null) {
            return null;
        }

        $this->reset();
        $rows = Person::query()->with('avatar')->whereIn('id', $path)->get()->keyBy('id');
        foreach ($path as $id) {
            if ($rows->has($id)) {
                $this->add($rows->get($id));
            }
        }

        $bottom = end($path);
        $blood = $path;
        $expandable = [];
        if ($down > 0) {
            $blood = array_merge($blood, $this->collectDescendants([$bottom], $down, $expandable));
        }
        $this->addSpousesAndCoParents(array_unique($blood));

        return $this->result($this->persons[$path[0]], 'lineage', [
            'path' => $path,
            'down' => $down,
            'expandable' => $expandable,
        ]);
    }

    // ------------------------------------------------------------------

    private function reset(): void
    {
        $this->persons = [];
        $this->truncated = false;
    }

    private function add(Person $person): void
    {
        $this->persons[$person->id] = $person;
    }

    private function full(): bool
    {
        if (count($this->persons) >= $this->maxNodes) {
            $this->truncated = true;

            return true;
        }

        return false;
    }

    /**
     * جمع‌آوری نوادگان به صورت سطح به سطح.
     *
     * @param  string[]  $startIds
     * @param  array  $expandable  شناسه کسانی که فرزند دارند ولی به دلیل محدودیت عمق نمایش داده نشده‌اند
     * @return string[] شناسه همه اعضای خونی (شامل شروع)
     */
    private function collectDescendants(array $startIds, int $depth, ?array &$expandable = []): array
    {
        $expandable = [];
        $blood = $startIds;
        $frontier = $startIds;

        for ($level = 1; $level <= $depth && $frontier; $level++) {
            if ($this->full()) {
                break;
            }
            $children = $this->childrenOf($frontier);
            $next = [];
            foreach ($children as $child) {
                if (isset($this->persons[$child->id]) && ! in_array($child->id, $frontier, true)) {
                    // قبلاً اضافه شده (مثلاً ازدواج فامیلی) - دوباره پیمایش نمی‌شود
                    continue;
                }
                $this->add($child);
                $next[] = $child->id;
                $blood[] = $child->id;
            }
            $frontier = array_values(array_unique($next));
        }

        // آخرین نسلِ نمایش داده‌شده: چه کسانی فرزند دارند؟ (برای نمایش دکمه «ادامه»)
        if ($frontier) {
            $expandable = Person::query()
                ->where(fn (Builder $q) => $q->whereIn('father_id', $frontier)->orWhereIn('mother_id', $frontier))
                ->get(['father_id', 'mother_id'])
                ->flatMap(fn ($r) => [$r->father_id, $r->mother_id])
                ->filter(fn ($id) => $id && in_array($id, $frontier, true))
                ->unique()->values()->all();
        }

        return array_values(array_unique($blood));
    }

    /** @return string[] شناسه نیاکانی که والد دارند ولی والدشان نمایش داده نشده */
    private function collectAncestors(Person $root, int $depth): array
    {
        $frontier = [$root];
        for ($level = 1; $level <= $depth && $frontier; $level++) {
            if ($this->full()) {
                break;
            }
            $ids = [];
            foreach ($frontier as $p) {
                foreach ([$p->father_id, $p->mother_id] as $pid) {
                    if ($pid && ! isset($this->persons[$pid])) {
                        $ids[$pid] = true;
                    }
                }
            }
            if (! $ids) {
                $frontier = [];
                break;
            }
            $parents = Person::query()->with('avatar')->whereIn('id', array_keys($ids))->get();
            foreach ($parents as $parent) {
                $this->add($parent);
            }
            $frontier = $parents->all();
        }

        return collect($frontier)
            ->filter(fn (Person $p) => $p->father_id || $p->mother_id)
            ->pluck('id')->values()->all();
    }

    private function childrenOf(array $parentIds): Collection
    {
        return Person::query()
            ->with('avatar')
            ->where(fn (Builder $q) => $q->whereIn('father_id', $parentIds)->orWhereIn('mother_id', $parentIds))
            ->orderByRaw('birth_order IS NULL, birth_order')
            ->orderByRaw('birth_date IS NULL, birth_date')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * افزودن همسرانِ اعضای خونی و «والد دیگرِ» فرزندان
     * (حتی اگر ازدواجی بینشان ثبت نشده باشد).
     *
     * @param  string[]  $bloodIds
     */
    private function addSpousesAndCoParents(array $bloodIds): void
    {
        $missing = [];
        foreach ($this->persons as $p) {
            foreach ([$p->father_id, $p->mother_id] as $pid) {
                if ($pid && ! isset($this->persons[$pid]) && in_array($p->id, $bloodIds, true)) {
                    // فقط والدِ دیگرِ فرزندانی که یکی از والدینشان در درخت است
                    $otherParentInTree = ($p->father_id && isset($this->persons[$p->father_id]))
                        || ($p->mother_id && isset($this->persons[$p->mother_id]));
                    if ($otherParentInTree) {
                        $missing[$pid] = true;
                    }
                }
            }
        }

        foreach (array_chunk($bloodIds, 500) as $chunk) {
            $marriages = Marriage::query()
                ->where(fn (Builder $q) => $q->whereIn('husband_id', $chunk)->orWhereIn('wife_id', $chunk))
                ->get(['husband_id', 'wife_id']);
            foreach ($marriages as $m) {
                foreach ([$m->husband_id, $m->wife_id] as $pid) {
                    if (! isset($this->persons[$pid])) {
                        $missing[$pid] = true;
                    }
                }
            }
        }

        if ($missing) {
            foreach (array_chunk(array_keys($missing), 500) as $chunk) {
                foreach (Person::query()->with('avatar')->whereIn('id', $chunk)->get() as $p) {
                    $this->add($p);
                }
            }
        }
    }

    /**
     * پیدا کردن مسیر از ancestor به descendant (پیمایش رو به بالا از descendant)
     *
     * @return string[]|null شناسه‌ها از ancestor تا descendant
     */
    private function findPath(Person $ancestor, Person $descendant): ?array
    {
        if ($ancestor->id === $descendant->id) {
            return [$ancestor->id];
        }

        $childOf = []; // parentId => childId (برای بازسازی مسیر)
        $frontier = [$descendant];
        $seen = [$descendant->id => true];
        $maxDepth = (int) config('pedigree.tree.max_depth', 30);

        for ($depth = 1; $depth <= $maxDepth && $frontier; $depth++) {
            $ids = [];
            foreach ($frontier as $p) {
                foreach ([$p->father_id, $p->mother_id] as $pid) {
                    if ($pid && ! isset($seen[$pid])) {
                        $seen[$pid] = true;
                        $childOf[$pid] = $p->id;
                        $ids[] = $pid;
                    }
                }
            }
            if (in_array($ancestor->id, $ids, true)) {
                $path = [$ancestor->id];
                $cursor = $ancestor->id;
                while (isset($childOf[$cursor])) {
                    $cursor = $childOf[$cursor];
                    $path[] = $cursor;
                }

                return $path;
            }
            $frontier = $ids ? Person::query()->whereIn('id', $ids)->get(['id', 'father_id', 'mother_id'])->all() : [];
        }

        return null;
    }

    private function result(Person $focus, string $mode, array $meta): array
    {
        $ids = array_keys($this->persons);
        $marriages = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Marriage::query()
                ->whereIn('husband_id', $chunk)
                ->orderBy('sort_order')->orderBy('marriage_date')->orderBy('created_at')
                ->get();
            foreach ($rows as $m) {
                if (isset($this->persons[$m->wife_id])) {
                    $marriages[$m->id] = $m;
                }
            }
        }

        return [
            'mode' => $mode,
            'focus' => $focus->id,
            'persons' => array_values(array_map([NodePresenter::class, 'person'], $this->persons)),
            'marriages' => array_values(array_map([NodePresenter::class, 'marriage'], $marriages)),
            'meta' => $meta + [
                'count' => count($this->persons),
                'truncated' => $this->truncated,
            ],
        ];
    }
}
