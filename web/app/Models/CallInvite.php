<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * دعوت به تماس با لینک سرویس بیرونی (تماس گروهی واتس‌اپ، گوگل‌میت، اسکای‌روم، جیتسی ...)
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $url
 * @property string $title
 */
class CallInvite extends Model
{
    protected $fillable = ['provider', 'url', 'title', 'starts_at', 'recipients'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'recipients' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'call_invite_user');
    }
}
