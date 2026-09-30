<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** پیام راه‌اندازی تماس (SDP یا نامزدهای ICE) از یک شرکت‌کننده به دیگری؛ پس از چند دقیقه پاک می‌شود */
class CallSignal extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['offer', 'answer', 'candidates', 'bye'];

    protected $fillable = ['call_id', 'from_user_id', 'to_user_id', 'type', 'payload'];
}
