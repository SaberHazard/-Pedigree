<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * حساب کاربری (ورود) یک شخص.
 *
 * @property int $id
 * @property ?string $person_id
 * @property ?string $password
 * @property string $role
 * @property string $status
 * @property ?array $preferences
 * @property ?Carbon $last_login_at
 * @property ?Carbon $otp_verified_at
 * @property-read ?Person $person
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    /** ثبت‌نام کرده ولی هنوز مدیر عضویتش را تأیید نکرده است (هیچ‌چیز از شجره‌نامه نمی‌بیند) */
    public const STATUS_PENDING = 'pending';

    protected $fillable = ['person_id', 'password', 'role', 'status', 'preferences'];

    /** مقدار پیش‌فرض همان پیش‌فرض ستون‌ها (تا مدلِ تازه‌ساخته پیش از refresh «فعال» باشد) */
    protected $attributes = ['role' => self::ROLE_MEMBER, 'status' => self::STATUS_ACTIVE];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'preferences' => 'array',
            'last_login_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'group_muted_until' => 'datetime',
            'group_read_id' => 'integer',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** نام نمایشی کاربر (از روی شخص متصل) */
    public function displayName(): string
    {
        return $this->person?->fullName() ?? ('کاربر #'.$this->id);
    }

    /** شماره موبایل برای کانال پیامک */
    public function routeNotificationForSms(): ?string
    {
        return $this->person?->phone;
    }
}
