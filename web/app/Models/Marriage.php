<?php

namespace App\Models;

use App\Support\PartialDate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ازدواج بین دو شخص.
 *
 * @property string $id
 * @property string $husband_id
 * @property string $wife_id
 * @property string $status married|divorced|widowed
 * @property ?string $marriage_date
 * @property ?string $end_date
 * @property int $sort_order
 */
class Marriage extends Model
{
    use HasUuids;

    public const STATUSES = ['married', 'divorced', 'widowed'];

    protected $fillable = ['husband_id', 'wife_id', 'status', 'marriage_date', 'end_date', 'sort_order', 'notes'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (Marriage $marriage) {
            foreach (['marriage_date', 'end_date'] as $field) {
                if ($marriage->isDirty($field)) {
                    $marriage->{$field} = PartialDate::normalize($marriage->{$field});
                }
            }
        });
    }

    /** ثبت‌کننده این ازدواج */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function husband(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'husband_id');
    }

    public function wife(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'wife_id');
    }

    /** شناسه همسرِ طرف مقابل */
    public function partnerOf(string $personId): string
    {
        return $this->husband_id === $personId ? $this->wife_id : $this->husband_id;
    }
}
