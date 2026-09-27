<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک سابقه در رزومه شخص (تحصیل، کار، سربازی، جایزه ...)
 *
 * @property int $id
 * @property string $person_id
 * @property string $type
 * @property string $title
 * @property ?string $organization
 * @property ?string $start_date
 * @property ?string $end_date
 * @property bool $is_current
 * @property ?int $created_by
 * @property ?int $updated_by
 */
class ResumeItem extends Model
{
    protected $fillable = ['type', 'title', 'organization', 'location', 'start_date', 'end_date', 'is_current', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'sort_order' => 'integer'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
