<?php

namespace App\Services\People;

use App\Exceptions\DomainException;
use App\Models\Marriage;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * افزودن بستگانِ «جدید» به یک شخص (فرزند، همسر، پدر، مادر، خواهر/برادر).
 *
 * برای وصل کردن اشخاصِ «موجود» از LinkService استفاده کنید.
 */
class RelativeService
{
    public const TYPES = ['child', 'spouse', 'father', 'mother', 'sibling'];

    public function __construct(
        private readonly PersonService $persons,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array  $data  اطلاعات شخص جدید
     * @param  array  $options  other_parent_id (برای فرزند)، marriage (برای همسر)
     */
    public function add(Person $anchor, string $type, array $data, User $actor, array $options = []): Person
    {
        return DB::transaction(function () use ($anchor, $type, $data, $actor, $options) {
            return match ($type) {
                'child' => $this->addChild($anchor, $data, $actor, $options['other_parent_id'] ?? null),
                'spouse' => $this->addSpouse($anchor, $data, $actor, $options['marriage'] ?? []),
                'father' => $this->addParent($anchor, $data, $actor, Person::MALE),
                'mother' => $this->addParent($anchor, $data, $actor, Person::FEMALE),
                'sibling' => $this->addSibling($anchor, $data, $actor),
                default => throw new DomainException('نوع نسبت نامعتبر است.'),
            };
        });
    }

    private function addChild(Person $parent, array $data, User $actor, ?string $otherParentId): Person
    {
        $other = null;
        if ($otherParentId) {
            $other = Person::find($otherParentId);
            if (! $other) {
                throw new DomainException('والد دوم پیدا نشد.');
            }
            if ($other->gender === $parent->gender) {
                throw new DomainException('والد دوم باید از جنس مخالف باشد.');
            }
        }

        $child = $this->persons->create($data, $actor);
        if ($parent->isMale()) {
            $child->father_id = $parent->id;
            $child->mother_id = $other?->id;
        } else {
            $child->mother_id = $parent->id;
            $child->father_id = $other?->id;
        }
        $child->save();

        if ($other) {
            $this->ensureMarriage($parent, $other, $actor);
        }

        $this->audit->log('relation.child_added', $parent, ['child' => $child->id, 'name' => $child->fullName()], $actor);

        return $child;
    }

    private function addSpouse(Person $anchor, array $data, User $actor, array $marriage): Person
    {
        // جنسیت همسر مخالف شخص است
        $data['gender'] = $anchor->isMale() ? Person::FEMALE : Person::MALE;
        $spouse = $this->persons->create($data, $actor);
        $this->ensureMarriage($anchor, $spouse, $actor, $marriage);
        $this->audit->log('relation.spouse_added', $anchor, ['spouse' => $spouse->id, 'name' => $spouse->fullName()], $actor);

        return $spouse;
    }

    private function addParent(Person $child, array $data, User $actor, string $gender): Person
    {
        $field = $gender === Person::MALE ? 'father_id' : 'mother_id';
        if ($child->{$field}) {
            throw new DomainException($gender === Person::MALE ? 'این شخص از قبل پدر دارد.' : 'این شخص از قبل مادر دارد.');
        }

        $data['gender'] = $gender;
        $parent = $this->persons->create($data, $actor);
        $child->{$field} = $parent->id;
        $child->save();

        // پدر و مادر را به هم وصل کن
        $otherField = $field === 'father_id' ? 'mother_id' : 'father_id';
        if ($child->{$otherField} && ($other = Person::find($child->{$otherField}))) {
            $this->ensureMarriage($parent, $other, $actor);
        }

        $this->audit->log('relation.parent_added', $child, [$field => $parent->id, 'name' => $parent->fullName()], $actor);

        return $parent;
    }

    private function addSibling(Person $anchor, array $data, User $actor): Person
    {
        if (! $anchor->father_id && ! $anchor->mother_id) {
            throw new DomainException('برای افزودن خواهر یا برادر ابتدا پدر یا مادر این شخص را ثبت کنید.');
        }

        $sibling = $this->persons->create($data, $actor);
        $sibling->father_id = $anchor->father_id;
        $sibling->mother_id = $anchor->mother_id;
        $sibling->save();

        $this->audit->log('relation.sibling_added', $anchor, ['sibling' => $sibling->id, 'name' => $sibling->fullName()], $actor);

        return $sibling;
    }

    /** ثبت ازدواج بین دو نفر (اگر از قبل نباشد) */
    public function ensureMarriage(Person $a, Person $b, User $actor, array $attributes = []): Marriage
    {
        if ($a->gender === $b->gender) {
            throw new DomainException('ازدواج فقط بین زن و مرد قابل ثبت است.');
        }
        $husband = $a->isMale() ? $a : $b;
        $wife = $a->isMale() ? $b : $a;

        $existing = Marriage::where('husband_id', $husband->id)->where('wife_id', $wife->id)->first();
        if ($existing) {
            return $existing;
        }

        $marriage = new Marriage(array_intersect_key($attributes, array_flip(['status', 'marriage_date', 'end_date', 'notes', 'sort_order'])));
        $marriage->husband_id = $husband->id;
        $marriage->wife_id = $wife->id;
        $marriage->status ??= 'married';
        $marriage->sort_order ??= Marriage::where('husband_id', $husband->id)->count();
        $marriage->created_by = $actor->id;
        $marriage->save();

        return $marriage;
    }
}
