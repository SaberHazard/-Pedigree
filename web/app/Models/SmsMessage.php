<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * پیامکی که یک عضو از پنل پیامکی سایت فرستاده (فعلاً تبریک تولد).
 * شماره کامل گیرنده ذخیره نمی‌شود (فقط phone_hint ماسک‌شده).
 *
 * @property int $id
 * @property ?int $sender_user_id
 * @property ?string $recipient_person_id
 * @property string $kind
 * @property string $provider
 * @property string $status sent|failed
 * @property bool $auto
 * @property string $body
 * @property ?string $phone_hint
 * @property ?string $error
 * @property Carbon $sent_on
 */
class SmsMessage extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['auto' => 'boolean', 'sent_on' => 'date'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'recipient_person_id')->withTrashed();
    }
}
