<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * پیشنهاد ویرایش پروفایل یا ازدواج از طرف بستگان درجه دو و سه (تا تأیید مدیر در سایت دیده نمی‌شود)
 *
 * @property array $changes
 * @property array $original
 */
class EditRequest extends Model
{
    public const KIND_PERSON = 'person';

    public const KIND_MARRIAGE = 'marriage';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['kind', 'person_id', 'marriage_id', 'requested_by', 'degree', 'changes', 'original', 'reason'];

    protected function casts(): array
    {
        return [
            'changes' => 'encrypted:array',
            'original' => 'encrypted:array',
            'decided_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    public function marriage(): BelongsTo
    {
        return $this->belongsTo(Marriage::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
