<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupReport extends Model
{
    protected $fillable = ['message_id', 'user_id', 'reason'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(GroupMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
