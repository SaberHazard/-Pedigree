<?php

namespace App\Models;

use App\Support\BlindIndex;
use App\Support\PartialDate;
use App\Support\PersianText;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/**
 * یک شخص در شجره‌نامه.
 *
 * @property string $id
 * @property string $code
 * @property string $first_name
 * @property ?string $last_name
 * @property ?string $nickname
 * @property ?string $title
 * @property string $gender  m|f
 * @property ?string $father_id
 * @property ?string $mother_id
 * @property ?int $birth_order
 * @property ?string $birth_date
 * @property bool $is_deceased
 * @property ?string $death_date
 * @property ?string $national_code
 * @property ?string $phone
 * @property ?string $birth_cert_no
 * @property ?string $avatar_media_id
 * @property bool $is_locked
 * @property ?int $created_by
 */
class Person extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'persons';

    public const MALE = 'm';

    public const FEMALE = 'f';

    /** فیلدهای عمومی که از فرم ویرایش پر می‌شوند (فیلدهای حساس جداگانه ست می‌شوند) */
    protected $fillable = [
        'first_name', 'last_name', 'nickname', 'title', 'gender',
        'birth_order', 'birth_date', 'birth_place',
        'is_deceased', 'death_date', 'death_place', 'burial_place',
        'birth_cert_place', 'email', 'occupation', 'education', 'residence', 'biography',
    ];

    protected $hidden = [
        'national_code', 'national_code_hash', 'birth_cert_no', 'phone', 'phone_hash', 'search_text',
    ];

    protected function casts(): array
    {
        return [
            'is_deceased' => 'boolean',
            'is_locked' => 'boolean',
            'birth_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Person $person) {
            if (empty($person->code)) {
                $person->code = self::generateCode();
            }
        });

        static::saving(function (Person $person) {
            // یکدست‌سازی متن‌ها قبل از ذخیره
            foreach (['first_name', 'last_name', 'nickname', 'title', 'birth_place', 'death_place', 'burial_place', 'occupation', 'education', 'residence', 'birth_cert_place'] as $field) {
                if ($person->isDirty($field)) {
                    $value = PersianText::normalize($person->{$field});
                    $person->{$field} = $value === '' ? null : $value;
                }
            }
            foreach (['birth_date', 'death_date'] as $field) {
                if ($person->isDirty($field)) {
                    $person->{$field} = PartialDate::normalize($person->{$field});
                }
            }
            $person->search_text = mb_substr(PersianText::searchable(implode(' ', array_filter([
                $person->title, $person->first_name, $person->last_name, $person->nickname, $person->code,
            ]))), 0, 500);
        });
    }

    /** کد کوتاه ۸ کاراکتری خوانا (بدون حروف گیج‌کننده مثل O و 0) */
    public static function generateCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    // ------------------------------------------------------------------
    // فیلدهای رمزنگاری‌شده (هم مقدار رمز و هم هش جستجو را ست می‌کنند)
    // ------------------------------------------------------------------

    protected function nationalCode(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::decryptOrNull($value),
            set: fn (?string $value) => [
                'national_code' => $value === null ? null : Crypt::encryptString($value),
                'national_code_hash' => $value === null ? null : BlindIndex::make($value, 'national_code'),
            ],
        );
    }

    protected function phone(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::decryptOrNull($value),
            set: fn (?string $value) => [
                'phone' => $value === null ? null : Crypt::encryptString($value),
                'phone_hash' => $value === null ? null : BlindIndex::make($value, 'phone'),
            ],
        );
    }

    protected function birthCertNo(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::decryptOrNull($value),
            set: fn (?string $value) => $value === null ? null : Crypt::encryptString($value),
        );
    }

    private static function decryptOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // اگر APP_KEY عوض شده باشد مقدار قابل بازیابی نیست
            return null;
        }
    }

    /** پیدا کردن شخص با شماره موبایل (از روی هش) */
    public static function findByPhone(string $normalizedPhone): ?self
    {
        return self::where('phone_hash', BlindIndex::make($normalizedPhone, 'phone'))->first();
    }

    /** پیدا کردن شخص با کد ملی (از روی هش) */
    public static function findByNationalCode(string $normalizedCode): ?self
    {
        return self::where('national_code_hash', BlindIndex::make($normalizedCode, 'national_code'))->first();
    }

    // ------------------------------------------------------------------
    // روابط
    // ------------------------------------------------------------------

    public function father(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'father_id');
    }

    public function mother(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'mother_id');
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function marriagesAsHusband(): HasMany
    {
        return $this->hasMany(Marriage::class, 'husband_id');
    }

    public function marriagesAsWife(): HasMany
    {
        return $this->hasMany(Marriage::class, 'wife_id');
    }

    /** همه ازدواج‌های این شخص (به ترتیب) */
    public function marriages(): Builder
    {
        return Marriage::query()
            ->where(fn (Builder $q) => $q->where('husband_id', $this->id)->orWhere('wife_id', $this->id))
            ->orderBy('sort_order')
            ->orderBy('marriage_date')
            ->orderBy('created_at');
    }

    /** کوئری فرزندان (از هر همسری) */
    public function childrenQuery(): Builder
    {
        return self::query()
            ->where(fn (Builder $q) => $q->where('father_id', $this->id)->orWhere('mother_id', $this->id))
            ->orderByRaw('birth_order IS NULL, birth_order')
            ->orderByRaw('birth_date IS NULL, birth_date')
            ->orderBy('created_at');
    }

    /** کوئری خواهر و برادرها (تنی و ناتنی) - خود شخص را شامل نمی‌شود */
    public function siblingsQuery(): Builder
    {
        return self::query()
            ->where('id', '!=', $this->id)
            ->where(function (Builder $q) {
                $has = false;
                if ($this->father_id) {
                    $q->orWhere('father_id', $this->father_id);
                    $has = true;
                }
                if ($this->mother_id) {
                    $q->orWhere('mother_id', $this->mother_id);
                    $has = true;
                }
                if (! $has) {
                    // بدون والدین، خواهر و برادری قابل تشخیص نیست
                    $q->whereRaw('1 = 0');
                }
            })
            ->orderByRaw('birth_order IS NULL, birth_order')
            ->orderByRaw('birth_date IS NULL, birth_date');
    }

    /** همسران (با ترتیب ازدواج) */
    public function spouses(): Collection
    {
        $marriages = $this->marriages()->get();
        $ids = $marriages->map(fn (Marriage $m) => $m->husband_id === $this->id ? $m->wife_id : $m->husband_id);

        $persons = self::whereIn('id', $ids)->get()->keyBy('id');

        return new Collection($ids->map(fn ($id) => $persons->get($id))->filter()->values()->all());
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    public function isMale(): bool
    {
        return $this->gender === self::MALE;
    }

    /** نام کامل با عنوان: «حاج محمد احمدی» */
    public function fullName(bool $withTitle = true): string
    {
        return trim(implode(' ', array_filter([
            $withTitle ? $this->title : null,
            $this->first_name,
            $this->last_name,
        ])));
    }

    /**
     * آیا صاحب این پروفایل حساب فعال دارد و حداقل یکبار وارد شده؟
     * (پروفایل «ادعا شده»؛ در این حالت اطلاعات حساس فقط دست خودش است)
     */
    public function hasActiveAccount(): bool
    {
        $user = $this->relationLoaded('user') ? $this->user : $this->user()->first();

        return $user !== null && $user->status === User::STATUS_ACTIVE && $user->last_login_at !== null;
    }
}
