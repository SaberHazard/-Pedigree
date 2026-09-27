<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * عکس یا ویدیوی یک شخص.
 *
 * @property string $id
 * @property string $person_id
 * @property ?int $uploaded_by
 * @property string $type image|video
 * @property string $status pending|approved|rejected
 * @property ?string $approval_mode auto|owner|vote|admin
 * @property string $processing queued|processing|ready|failed
 * @property string $disk
 * @property string $path
 * @property ?array $variants
 * @property string $mime
 * @property int $size
 * @property bool $set_as_avatar
 * @property string $category gallery|story
 */
class Media extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const CATEGORY_GALLERY = 'gallery';

    public const CATEGORY_STORY = 'story';

    protected $fillable = ['caption', 'description', 'taken_at', 'category'];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'set_as_avatar' => 'boolean',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(MediaVote::class);
    }

    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** مسیر فایل یک نسخه (original, thumb, medium, poster) */
    public function pathFor(string $variant): ?string
    {
        if ($variant === 'original') {
            return $this->path;
        }

        return $this->variants[$variant] ?? null;
    }

    /** لینک امضاشده یک نسخه؛ اگر آن نسخه وجود نداشته باشد نسخه اصلی */
    public function url(string $variant = 'original'): ?string
    {
        if ($variant !== 'original' && $this->pathFor($variant) === null) {
            // ویدیو بدون پوستر: لینکی برای تصویر کوچک ندارد
            if ($this->isVideo()) {
                return null;
            }
            $variant = 'original';
        }

        return MediaUrl::for($this, $variant);
    }
}
