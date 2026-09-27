<?php

namespace App\Services\Media;

use App\Exceptions\DomainException;
use App\Jobs\ProcessVideo;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ذخیره و مدیریت فایل‌های رسانه.
 *
 * ساختار ذخیره روی دیسک خصوصی:
 *   images/2026/09/{uuid}.webp         (نسخه اصلی)
 *   images/2026/09/{uuid}_thumb.webp
 *   videos/2026/09/{uuid}.mp4
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
        $type = str_starts_with($mime, 'video/') ? Media::TYPE_VIDEO : Media::TYPE_IMAGE;

        if ($type === Media::TYPE_VIDEO && ! config('pedigree.media.video.enabled')) {
            throw new DomainException('آپلود ویدیو غیرفعال است.');
        }
        if ($asAvatar && $type !== Media::TYPE_IMAGE) {
            throw new DomainException('عکس پروفایل باید تصویر باشد.');
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
            $path = "{$folder}/{$id}.webp";
            Storage::disk($disk)->put($path, $result['main']);
            $variants = [];
            foreach ($result['variants'] as $name => $binary) {
                $variants[$name] = "{$folder}/{$id}_{$name}.webp";
                Storage::disk($disk)->put($variants[$name], $binary);
            }
            $media->path = $path;
            $media->variants = $variants;
            $media->mime = 'image/webp';
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
            $this->audit->log('media.uploaded', $media, ['person' => $person->id, 'type' => $media->type], $actor);
            $this->approvals->initiate($media, $actor);
        });

        if ($media->isVideo()) {
            ProcessVideo::dispatch($media->id);
        }

        return $media->refresh();
    }

    /** حذف فایل‌های فیزیکی یک رسانه */
    public function deleteFiles(Media $media): void
    {
        $paths = array_filter(array_merge([$media->path], array_values($media->variants ?? [])));
        Storage::disk($media->disk)->delete($paths);
    }

    public function delete(Media $media, User $actor): void
    {
        DB::transaction(function () use ($media, $actor) {
            Person::where('avatar_media_id', $media->id)->update(['avatar_media_id' => null]);
            $media->delete();
            $this->audit->log('media.deleted', $media, ['person' => $media->person_id], $actor);
        });
        $this->deleteFiles($media);
    }

    private function folder(string $type): string
    {
        return ($type === Media::TYPE_IMAGE ? 'images' : 'videos').'/'.now()->format('Y/m');
    }

    private function cleanMeta(array $meta): array
    {
        return array_intersect_key($meta, array_flip(['caption', 'description', 'taken_at']));
    }
}
