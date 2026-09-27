<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * خاندان: یک عنوان + جد اعلا (ریشه درخت)
 */
class Family extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'description', 'root_person_id', 'color'];

    public function root(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'root_person_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
