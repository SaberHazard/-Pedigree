<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * پرداخت حمایتی از درگاه (زرین‌پال، زیبال، Pay.ir)؛ فقط پس از تأیید سرور-به-سرور «پرداخت‌شده» می‌شود
 */
class Donation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['user_id', 'gateway', 'amount', 'callback_token', 'message', 'anonymous', 'ip'];

    protected $hidden = ['callback_token', 'authority', 'ip'];

    protected function casts(): array
    {
        return ['anonymous' => 'boolean', 'paid_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
