<?php

namespace App\Services\Media;

use App\Exceptions\DomainException;
use App\Jobs\ProcessVideo;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Social\SocialAvatarService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * ذخیره و مدیریت فایل‌های رسانه.
 *
 * ساختار ذخیره روی دیسک خصوصی:
 *   images/2026/09/{uuid}.jpg          (نسخه اصلی؛ هر قالبی به JPEG تبدیل می‌شود)
 *   images/2026/09/{uuid}_thumb.jpg
 *   videos/2026/09/{uuid}.mp4          (هر ویدیویی به MP4 تبدیل می‌شود)
 */
class MediaService
{
    public function __construct(
        private readonly ImageProcessor $images,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Person $person, UploadedFile $file, User $actor, array $meta = [], bool $asAvatar = false): Media
    {
        $mime = (string) $file->getMimeType(); // از محتوای فایل (finfo)، نه پسوند
        $type = match (MediaFormats::classify((string) $file->getRealPath(), $mime)) {
            'image' => Media::TYPE_IMAGE,
            'video' => Media::TYPE_VIDEO,
            default => throw new DomainException('این نوع فایل پشتیبانی نمی‌شود؛ فقط عکس ('.MediaFormats::IMAGE_LABEL.') یا ویدیو.'),
        };

        if ($type === Media::TYPE_VIDEO && ! config('pedigree.media.video.enabled')) {
            throw new DomainException('آپلود ویدیو غیرفعال است.');
        }
        if ($asAvatar && $type !== Media::TYPE_IMAGE) {
            throw new DomainException('عکس پروفایل باید تصویر باشد.');
        }

        $this->assertQuota($actor, $type);
        if ($type === Media::TYPE_VIDEO) {
            $this->assertPlayableVideo((string) $file->getRealPath());
        }

        $checksum = hash_file('sha256', $file->getRealPath());
        $duplicate = Media::where('person_id', $person->id)->where('checksum', $checksum)
            ->where('status', '!=', Media::STATUS_REJECTED)->exists();
        if ($duplicate) {
            throw new DomainException('این فایل قبلاً برای همین شخص آپلود شده است.');
        }

        $disk = (string) config('pedigree.media.disk', 'media');
        $id = (string) Str::uuid7();
        $folder = $this->folder($type);

        $media = new Media($this->cleanMeta($meta));
        $media->id = $id;
        $media->person_id = $person->id;
        $media->uploaded_by = $actor->id;
        $media->type = $type;
        $media->status = Media::STATUS_PENDING;
        $media->disk = $disk;
        $media->checksum = $checksum;
        $media->set_as_avatar = $asAvatar;

        if ($type === Media::TYPE_IMAGE) {
            $result = $this->images->process($file->getRealPath());
            $path = "{$folder}/{$id}.jpg";
            Storage::disk($disk)->put($path, $result['main']);
            $variants = [];
            foreach ($result['variants'] as $name => $binary) {
                $variants[$name] = "{$folder}/{$id}_{$name}.jpg";
                Storage::disk($disk)->put($variants[$name], $binary);
            }
            $media->path = $path;
            $media->variants = $variants;
            $media->mime = 'image/jpeg';
            $media->size = strlen($result['main']);
            $media->width = $result['width'];
            $media->height = $result['height'];
            $media->processing = 'ready';
        } else {
            $ext = strtolower($file->guessExtension() ?: 'mp4');
            $path = "{$folder}/{$id}_src.{$ext}";
            Storage::disk($disk)->putFileAs($folder, $file, "{$id}_src.{$ext}");
            $media->path = $path;
            $media->variants = [];
            $media->mime = $mime;
            $media->size = (int) $file->getSize();
            $media->processing = 'queued';
        }

        DB::transaction(function () use ($media, $actor, $person) {
            $media->save();
            $this->audit->log('media.uploaded', $media, ['person' => $person->id, 'type' => $media->type, 'category' => $media->category, 'caption' => $media->caption], $actor);
            $this->approvals->initiate($media, $actor);
        });

        if ($media->isVideo()) {
            ProcessVideo::dispatch($media->id);
        }

        return $media->refresh();
    }

    /**
     * سقف آپلود روزانه هر عضو و صف تبدیل ویدیو (جلوگیری از پر کردن فضا و پردازنده سرور)
     *
     * @throws DomainException
     */
    public function assertQuota(User $actor, string $type): void
    {
        $since = now('Asia/Tehran')->startOfDay()->utc();
        $today = Media::withTrashed()->where('uploaded_by', $actor->id)->where('created_at', '>=', $since);
        if ((clone $today)->count() >= (int) config('pedigree.media.daily_uploads_per_user', 100)) {
            throw new DomainException('به سقف آپلود امروز رسیده‌اید؛ فردا دوباره امتحان کنید.', 429, 'upload_limit');
        }
        if ($type !== Media::TYPE_VIDEO) {
            return;
        }
        if ((clone $today)->where('type', Media::TYPE_VIDEO)->count() >= (int) config('pedigree.media.daily_videos_per_user', 20)) {
            throw new DomainException('به سقف آپلود ویدیوی امروز رسیده‌اید؛ فردا دوباره امتحان کنید.', 429, 'upload_limit');
        }
        $queued = Media::query()->where('type', Media::TYPE_VIDEO)->whereIn('processing', ['queued', 'processing'])->count();
        if ($queued >= (int) config('pedigree.media.video.queue_max', 30)) {
            throw new DomainException('ویدیوهای زیادی در صف فشرده‌سازی هستند؛ چند دقیقه بعد دوباره امتحان کنید.', 503, 'video_queue_full');
        }
    }

    /**
     * ویدیو پیش از پذیرفتن با ffprobe بررسی می‌شود (فایل خراب یا جعلی وارد صف تبدیل نمی‌شود)
     *
     * @throws DomainException
     */
    public function assertPlayableVideo(string $path): void
    {
        $ffprobe = (string) config('pedigree.media.video.ffprobe', 'ffprobe');
        try {
            $process = new Process([$ffprobe, '-v', 'error', ...ProcessVideo::inputGuard(), '-show_entries', 'stream=codec_type:format=duration', '-of', 'json', $path]);
            $process->setTimeout(30);
            $process->run();
        } catch (Throwable) {
            return; // ffprobe نصب نیست؛ بررسی در مرحله تبدیل
        }
        if ($process->getExitCode() === 127) {
            return;
        }
        $json = json_decode($process->getOutput(), true) ?: [];
        $hasVideo = collect($json['streams'] ?? [])->contains(fn ($s) => ($s['codec_type'] ?? null) === 'video');
        if (! $process->isSuccessful() || ! $hasVideo) {
            throw new DomainException('این ویدیو خراب است یا قالب آن پشتیبانی نمی‌شود.');
        }
    }

    /** حذف فایل‌های فیزیکی یک رسانه */
    public function deleteFiles(Media $media): void
    {
        $paths = array_filter(array_merge([$media->path], array_values($media->variants ?? [])));
        Storage::disk($media->disk)->delete($paths);
    }

    public function delete(Media $media, User $actor): void
    {
        $wasAvatar = Person::where('avatar_media_id', $media->id)->exists();
        DB::transaction(function () use ($media, $actor) {
            Person::where('avatar_media_id', $media->id)->update(['avatar_media_id' => null, 'avatar_source' => null]);
            $media->delete();
            $this->audit->log('media.deleted', $media, [
                'person' => $media->person_id, 'type' => $media->type, 'category' => $media->category,
                'caption' => $media->caption, 'uploaded_by' => $media->uploader?->displayName(),
            ], $actor);
        });
        $this->deleteFiles($media);

        // اگر عکس پروفایل حذف شد، عکس شبکه‌های اجتماعی (به ترتیب اولویت) جایگزین می‌شود
        if ($wasAvatar && $media->person) {
            app(SocialAvatarService::class)->fallback($media->person);
        }
    }

    private function folder(string $type): string
    {
        return ($type === Media::TYPE_IMAGE ? 'images' : 'videos').'/'.now()->format('Y/m');
    }

    private function cleanMeta(array $meta): array
    {
        $meta = array_intersect_key($meta, array_flip(['caption', 'description', 'taken_at', 'category']));
        $meta['category'] = ($meta['category'] ?? null) ?: Media::CATEGORY_GALLERY;

        return $meta;
    }
}
