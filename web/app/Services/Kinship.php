<?php

namespace App\Services;

use App\Models\Marriage;
use App\Models\Person;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;

/**
 * سرویس تشخیص خویشاوندی.
 *
 * همه پرسش‌های «این دو نفر چه نسبتی دارند؟» که در دسترسی‌ها و رأی‌گیری
 * لازم است اینجا پاسخ داده می‌شود. نتایج در طول یک درخواست کش می‌شوند.
 */
class Kinship
{
    /** @var array<string, bool> */
    private array $ancestorCache = [];

    /**
     * نسبت مستقیم viewer با target از دید viewer:
     *  self    : خودش است
     *  parent  : viewer پدر/مادرِ target است
     *  child   : viewer فرزندِ target است
     *  sibling : خواهر/برادر هستند
     *  spouse  : همسر هستند
     */
    public function directRelation(Person $viewer, Person $target): ?string
    {
        if ($viewer->id === $target->id) {
            return 'self';
        }
        if ($target->father_id === $viewer->id || $target->mother_id === $viewer->id) {
            return 'parent';
        }
        if ($viewer->father_id === $target->id || $viewer->mother_id === $target->id) {
            return 'child';
        }
        if (($viewer->father_id && $viewer->father_id === $target->father_id)
            || ($viewer->mother_id && $viewer->mother_id === $target->mother_id)) {
            return 'sibling';
        }
        if ($this->areMarried($viewer->id, $target->id)) {
            return 'spouse';
        }

        return null;
    }

    public function areMarried(string $a, string $b): bool
    {
        return Marriage::query()
            ->where(fn (Builder $q) => $q->where('husband_id', $a)->where('wife_id', $b))
            ->orWhere(fn (Builder $q) => $q->where('husband_id', $b)->where('wife_id', $a))
            ->exists();
    }

    /**
     * آیا ancestorId جدِ (پدر/مادر/پدربزرگ/...) شخص descendant است؟
     * پیمایش سطح به سطح به سمت بالا با حداکثر maxDepth نسل.
     */
    public function isAncestorOf(string $ancestorId, Person $descendant, int $maxDepth = 30): bool
    {
        $key = $ancestorId.'>'.$descendant->id.'#'.$maxDepth;
        if (isset($this->ancestorCache[$key])) {
            return $this->ancestorCache[$key];
        }

        $frontier = array_values(array_filter([$descendant->father_id, $descendant->mother_id]));
        $seen = [];
        for ($depth = 1; $depth <= $maxDepth && $frontier; $depth++) {
            if (in_array($ancestorId, $frontier, true)) {
                return $this->ancestorCache[$key] = true;
            }
            foreach ($frontier as $id) {
                $seen[$id] = true;
            }
            $next = [];
            $rows = Person::query()->whereIn('id', $frontier)->get(['id', 'father_id', 'mother_id']);
            foreach ($rows as $row) {
                foreach ([$row->father_id, $row->mother_id] as $pid) {
                    if ($pid && ! isset($seen[$pid])) {
                        $next[$pid] = true;
                    }
                }
            }
            $frontier = array_keys($next);
        }

        return $this->ancestorCache[$key] = false;
    }

    /** والدین موجود یک شخص */
    public function parentsOf(Person $person): Collection
    {
        $ids = array_values(array_filter([$person->father_id, $person->mother_id]));

        return $ids ? Person::query()->whereIn('id', $ids)->get() : new Collection;
    }

    public function childrenOf(Person $person): Collection
    {
        return $person->childrenQuery()->get();
    }

    public function siblingsOf(Person $person): Collection
    {
        return $person->siblingsQuery()->get();
    }

    public function spousesOf(Person $person): Collection
    {
        return $person->spouses();
    }

    /**
     * گروه‌های خویشاوندی با نام جمع (برای تنظیمات رأی‌گیری):
     * parents | children | siblings | spouses
     */
    public function group(Person $person, string $group): Collection
    {
        return match ($group) {
            'parents' => $this->parentsOf($person),
            'children' => $this->childrenOf($person),
            'siblings' => $this->siblingsOf($person),
            'spouses' => $this->spousesOf($person),
            default => new Collection,
        };
    }

    /**
     * شناسه نوادگان یک شخص تا maxDepth نسل (برای دسترسی نوادگانِ فرد درگذشته)
     *
     * @return string[]
     */
    public function descendantIds(Person $person, int $maxDepth): array
    {
        $result = [];
        $frontier = [$person->id];
        for ($depth = 1; $depth <= $maxDepth && $frontier; $depth++) {
            $children = Person::query()
                ->where(fn (Builder $q) => $q->whereIn('father_id', $frontier)->orWhereIn('mother_id', $frontier))
                ->pluck('id')
                ->all();
            $frontier = array_values(array_diff($children, $result));
            array_push($result, ...$frontier);
        }

        return $result;
    }
}
