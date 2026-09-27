<?php

namespace App\Models;

use App\Support\AttributedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک متن بلند پروفایل (چکیده، توضیحات، زندگی‌نامه، رزومه) با نویسنده هر تکه.
 *
 * @property int $id
 * @property string $person_id
 * @property string $field
 * @property array $segments [[user_id|null, "متن"], ...]
 * @property string $plain
 * @property int $revision
 * @property ?int $updated_by
 */
class PersonText extends Model
{
    protected $fillable = ['person_id', 'field'];

    protected function casts(): array
    {
        return ['segments' => 'array', 'revision' => 'integer'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PersonTextRevision::class)->orderByDesc('revision');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** تکه‌های معتبر (در برابر داده خراب محافظت می‌کند) */
    public function cleanSegments(): array
    {
        return AttributedText::sanitize($this->segments);
    }
}
