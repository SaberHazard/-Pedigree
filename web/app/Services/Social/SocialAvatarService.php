<?php

namespace App\Services\Social;

use App\Exceptions\DomainException;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\Media\ImageProcessor;
use App\Services\Media\MediaService;
use App\Support\SocialNetworks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * عکس پروفایل شبکه‌های اجتماعی:
 *  - پیش‌نمایش هنگام وارد کردن شناسه (نام و عکس، برای اطمینان از درست بودن شناسه)
 *  - ذخیره عکس هر شبکه در گالری شخص (دریافت خودکار یا آپلود دستی، مثلاً عکس واتس‌اپ)
 *  - اگر شخص در سایت عکس پروفایل ندارد، عکس شبکه‌ها به ترتیب اولویت
 *    (اینستاگرام ← واتس‌اپ ← تلگرام) عکس پروفایل او می‌شود.
 *
 * عکس‌ها مثل هر آپلود دیگری از مسیر تأیید (رأی بستگان) می‌گذرند: اگر خود شخص شناسه را
 * وارد کرده باشد خودکار تأیید می‌شود، وگرنه خود شخص/بستگان تأیید می‌کنند.
 */
class SocialAvatarService
{
    public function __construct(
        private readonly SocialProfileFetcher $fetcher,
        private readonly ImageProcessor $images,
    ) {}

    /**
     * پیش‌نمایش یک شناسه (نتیجه چند ساعت کش می‌شود تا سرورِ شبکه‌ها پشت سر هم صدا زده نشود)
     *
     * @return array{network:string, value:string, display:string, url:?string, fetchable:bool, name:?string, image:?string, error:?string}
     */
    public function preview(string $network, string $value): array
    {
        $base = [
            'network' => $network,
            'value' => $value,
            'display' => SocialNetworks::display($network, $value),
            'url' => SocialNetworks::url($network, $value),
            'fetchable' => $this->fetcher->canFetch($network, $value),
            'name' => null,
            'image' => null,
            'error' => null,
        ];
        if (! $base['fetchable']) {
            return $base;
        }

        $key = 'social-preview:'.hash('sha256', $network.'|'.strtolower($value));
        $minutes = (int) config('pedigree.social.preview_cache_minutes', 360);

        return array_merge($base, Cache::remember($key, now()->addMinutes($minutes), function () use ($network, $value) {
            try {
                $profile = $this->fetcher->fetch($network, $value);
                $thumb = $profile['image'] ? $this->images->thumbnailFromBinary($profile['image'], 256) : null;

                return ['name' => $profile['name'], 'image' => $thumb ? 'data:image/jpeg;base64,'.base64_encode($thumb) : null, 'error' => null];
            } catch (DomainException $e) {
                return ['name' => null, 'image' => null, 'error' => $e->getMessage()];
            } catch (Throwable) {
                return ['name' => null, 'image' => null, 'error' => 'دریافت اطلاعات از این شبکه ممکن نشد.'];
            }
        }));
    }

    /**
     * ذخیره عکس پروفایل یک شبکه برای شخص (دریافت از شبکه یا فایل آپلودشده)
     */
    public function store(Person $person, string $network, User $actor, ?UploadedFile $file = null): Media
    {
        $value = $person->social[$network] ?? null;
        if (! SocialNetworks::exists($network) || $value === null) {
            throw new DomainException('ابتدا شناسه یا شماره این شبکه را در پروفایل ثبت کنید.');
        }

        $temp = null;
        if ($file === null) {
            $profile = $this->fetcher->fetch($network, $value);
            if (! $profile['image']) {
                throw new DomainException('عکس پروفایل عمومی برای این حساب پیدا نشد. می‌توانید عکس را دستی آپلود کنید.', 404);
            }
            $temp = tempnam(sys_get_temp_dir(), 'social');
            file_put_contents($temp, $profile['image']);
            $file = new UploadedFile($temp, $network.'.img', null, null, true);
        }

        try {
            $existing = Media::query()->where('person_id', $person->id)
                ->where('checksum', hash_file('sha256', $file->getRealPath()))
                ->where('status', '!=', Media::STATUS_REJECTED)->first();
            if ($existing) {
                // همان عکس قبلی است
                $this->remember($person, $network, $existing, $value);

                return $existing;
            }

            $label = SocialNetworks::all()[$network]['label'];
            $media = app(MediaService::class)->store($person, $file, $actor, [
                'category' => Media::CATEGORY_SOCIAL,
                'caption' => "عکس پروفایل {$label} (".SocialNetworks::display($network, $value).')',
            ], $this->preferredOver($person->fresh(), $network));
            $this->remember($person, $network, $media, $value);
            if ($media->isApproved()) {
                $this->promote($media->fresh());
            }

            return $media->fresh();
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }
    }

    /**
     * پس از تأیید عکس یک شبکه: اگر شخص عکس پروفایلِ آپلودی ندارد و این شبکه اولویت بالاتری
     * از عکسِ فعلی دارد، عکس پروفایل می‌شود.
     */
    public function promote(Media $media): void
    {
        if (! $media->isApproved() || ! $media->isImage()) {
            return;
        }
        DB::transaction(function () use ($media) {
            $person = Person::query()->lockForUpdate()->find($media->person_id);
            $network = $person ? $this->networkOf($person, $media->id) : null;
            if ($network && $this->preferredOver($person, $network)) {
                $person->forceFill(['avatar_media_id' => $media->id, 'avatar_source' => 'social:'.$network])->saveQuietly();
            }
        });
    }

    /** وقتی عکس پروفایل حذف شد: بهترین عکس تأییدشده شبکه‌ها جایگزین می‌شود */
    public function fallback(Person $person): void
    {
        $person = $person->fresh();
        if ($person === null || $person->avatar_media_id !== null) {
            return;
        }
        $avatars = (array) ($person->social_avatars ?? []);
        foreach ($this->priority() as $network) {
            $id = $avatars[$network]['media_id'] ?? null;
            $media = $id ? Media::query()->where('person_id', $person->id)->find($id) : null;
            if ($media && $media->isApproved() && $media->isImage()) {
                $person->forceFill(['avatar_media_id' => $media->id, 'avatar_source' => 'social:'.$network])->saveQuietly();

                return;
            }
        }
    }

    /** آیا عکس این شبکه باید جای عکس پروفایل فعلی بنشیند؟ */
    public function preferredOver(Person $person, string $network): bool
    {
        $priority = $this->priority();
        $rank = array_search($network, $priority, true);
        if ($rank === false) {
            return false;
        }
        if ($person->avatar_media_id === null) {
            return true;
        }
        $source = (string) $person->avatar_source;
        if (! str_starts_with($source, 'social:')) {
            return false; // عکسی که در سایت آپلود یا انتخاب شده همیشه مقدم است
        }
        $current = array_search(substr($source, 7), $priority, true);

        return $current === false || $rank <= $current;
    }

    private function remember(Person $person, string $network, Media $media, string $value): void
    {
        DB::transaction(function () use ($person, $network, $media, $value) {
            $fresh = Person::query()->lockForUpdate()->find($person->id);
            $avatars = (array) ($fresh->social_avatars ?? []);
            $avatars[$network] = ['media_id' => $media->id, 'handle' => $value, 'at' => now()->getTimestamp()];
            $fresh->forceFill(['social_avatars' => $avatars])->saveQuietly();
        });
    }

    private function networkOf(Person $person, string $mediaId): ?string
    {
        foreach ((array) ($person->social_avatars ?? []) as $network => $row) {
            if (($row['media_id'] ?? null) === $mediaId) {
                return $network;
            }
        }

        return null;
    }

    /** @return string[] */
    private function priority(): array
    {
        return config('pedigree.profile.social_avatar_priority', ['instagram', 'whatsapp', 'telegram']);
    }
}
