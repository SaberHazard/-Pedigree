<?php

namespace App\Http\Resources;

use App\Models\Media;
use App\Models\Person;
use App\Models\PersonText;
use App\Models\ResumeItem;
use App\Services\Access\PersonAccess;
use App\Services\People\ProfileService;
use App\Services\Tree\NodePresenter;
use App\Support\AttributedText;
use App\Support\SocialNetworks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * نمایش کامل اطلاعات یک شخص.
 *
 * - فیلدهای حساس (کد ملی، شناسنامه) فقط برای کسی که دسترسی دارد پر می‌شوند و برای بقیه null هستند.
 * - نشانی و موقعیت خانه، و راه‌های ارتباطی طبق تنظیم خود شخص (همه / بستگان تا درجه ۴..۱ / فقط خودش).
 * - شماره واتس‌اپ و تلگرام هم مثل موبایل فقط برای مجازها.
 * - contributors: نویسندگان پروفایل با رنگ هر کدام (برای رنگ‌بندی متن‌ها و راهنمای رنگ).
 *
 * @mixin Person
 */
class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Person $person */
        $person = $this->resource;
        $user = $request->user();
        $access = app(PersonAccess::class);
        $profile = app(ProfileService::class);
        $sensitive = $access->canViewSensitive($user, $person);
        $location = $access->canViewLocation($user, $person);
        $contact = $access->canViewContact($user, $person);
        $canEdit = $access->canEdit($user, $person);
        $account = $person->user;
        $avatar = $person->avatar;

        // شماره واتس‌اپ/تلگرام فقط برای کسانی که اجازه دیدن موبایل را دارند
        $social = (array) ($person->social ?? []);
        $hiddenSocial = false;
        if (! $contact) {
            foreach ($social as $network => $value) {
                if (SocialNetworks::isPhone($network, $value)) {
                    unset($social[$network]);
                    $hiddenSocial = true;
                }
            }
        }

        $texts = $person->relationLoaded('texts') ? $person->texts : $person->texts()->get();
        $resume = $person->relationLoaded('resumeItems') ? $person->resumeItems : $person->resumeItems()->get();

        // همه کسانی که در این پروفایل چیزی نوشته‌اند
        $authorIds = array_column($person->field_meta ?? [], 'u');
        foreach ($texts as $text) {
            array_push($authorIds, ...AttributedText::authors($text->cleanSegments()));
        }
        foreach ($resume as $item) {
            $authorIds[] = $item->updated_by ?? $item->created_by;
        }

        return NodePresenter::person($person) + [
            'avatar_medium' => $avatar && $avatar->isApproved() ? $avatar->url('medium') : null,
            'avatar_media_id' => $person->avatar_media_id,
            'death_place' => $person->death_place,
            'burial_place' => $person->burial_place,
            'burial_location' => $person->burial_lat !== null && $person->burial_lng !== null
                ? ['lat' => $person->burial_lat, 'lng' => $person->burial_lng] : null,
            'education' => $person->education,
            'education_level' => $person->education_level,
            'education_field' => $person->education_field,
            'education_field_group' => $person->education_field_group,
            'honorific_mode' => $person->honorific_mode ?? 'auto',
            'education_institution' => $person->education_institution,
            'academic_rank' => $person->academic_rank,
            'workplace' => $person->workplace,
            'residence' => $person->residence,
            'country' => $person->country,
            'province' => $person->province,
            'city' => $person->city,
            'blood_type' => $person->blood_type,
            'languages' => $person->languages,
            'interests' => $person->interests,
            'custom_fields' => $person->custom_fields ?? [],
            'website' => $person->website,
            'social' => (object) $social,
            'social_profiles' => $this->socialProfiles($person, $social),
            'biography' => $person->biography,
            'is_locked' => $person->is_locked,

            // نشانی و موقعیت خانه (با اجازه خود شخص یا برای ویرایشگران)
            'address' => $location ? $person->address : null,
            'postal_code' => $location ? $person->postal_code : null,
            'home_location' => $location ? $person->homeLocation() : null,
            'location_visibility' => $person->location_visibility ?? 'd1',
            'has_home_location' => $person->home_lat !== null,
            'location_hidden' => ! $location && ($person->address || $person->home_lat !== null),

            // راه‌های ارتباطی
            'phone' => $contact ? $person->phone : null,
            'email' => $contact ? $person->email : null,
            'landline' => $contact ? $person->landline : null,
            'contact_visibility' => $person->contact_visibility ?? 'all',
            'accept_greeting_sms' => (bool) ($person->accept_greeting_sms ?? true),
            'contact_hidden' => ! $contact && ($person->phone_hash || $person->email || $person->landline || $hiddenSocial),

            // اطلاعات هویتی
            'national_code' => $sensitive ? $person->national_code : null,
            'birth_cert_no' => $sensitive ? $person->birth_cert_no : null,
            'birth_cert_place' => $sensitive ? $person->birth_cert_place : null,
            'has_national_code' => $person->national_code_hash !== null,
            'has_phone' => $person->phone_hash !== null,

            'account' => [
                'exists' => $account !== null,
                'active' => $person->hasActiveAccount(),
                'has_password' => $sensitive ? ($account?->password !== null) : null,
                'username' => $sensitive ? $account?->username : null,
            ],

            // متن‌های بلند با نویسنده هر تکه
            'texts' => (object) $texts->mapWithKeys(fn (PersonText $t) => [$t->field => [
                'segments' => $t->cleanSegments(),
                'revision' => $t->revision,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ]])->all(),
            'resume' => $resume->map(fn (ResumeItem $item) => self::resumeItem($item))->values(),
            'field_meta' => (object) array_map(fn ($m) => ['u' => $m['u'] ?? null, 't' => $m['t'] ?? null], $person->field_meta ?? []),
            'contributors' => $profile->contributors($person, $authorIds),
            'completeness' => $canEdit ? $profile->completeness(
                $person,
                $texts->filter(fn (PersonText $t) => $t->plain !== '')->pluck('field')->all(),
                $resume->count(),
                $avatar !== null && $avatar->isApproved(),
            ) : null,

            'created_at' => $person->created_at?->toIso8601String(),
            'updated_at' => $person->updated_at?->toIso8601String(),
            'created_by' => $person->creator?->displayName(),
            'permissions' => $access->summary($user, $person),
        ];
    }

    /**
     * فهرست مرتب شبکه‌ها با لینک مستقیم و عکس پروفایل دریافت‌شده (برای نمایش در پروفایل)
     *
     * @return array<int, array>
     */
    private function socialProfiles(Person $person, array $social): array
    {
        $avatars = (array) ($person->social_avatars ?? []);
        $mediaIds = array_filter(array_map(fn ($a) => $a['media_id'] ?? null, $avatars));
        $media = $mediaIds ? Media::query()->whereIn('id', array_values($mediaIds))->where('status', Media::STATUS_APPROVED)->get()->keyBy('id') : collect();

        $out = [];
        foreach (SocialNetworks::all() as $network => $def) {
            if (! isset($social[$network])) {
                continue;
            }
            $value = $social[$network];
            $photo = $media->get($avatars[$network]['media_id'] ?? '');
            // عکسِ شناسه قبلی نمایش داده نمی‌شود
            if ($photo && ($avatars[$network]['handle'] ?? null) !== $value) {
                $photo = null;
            }
            $out[] = [
                'network' => $network,
                'label' => $def['label'],
                'value' => $value,
                'display' => SocialNetworks::display($network, $value),
                'url' => SocialNetworks::url($network, $value),
                'photo' => $photo ? ['thumb' => $photo->url('thumb'), 'medium' => $photo->url('medium'), 'media_id' => $photo->id] : null,
            ];
        }

        return $out;
    }

    public static function resumeItem(ResumeItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'title' => $item->title,
            'organization' => $item->organization,
            'location' => $item->location,
            'start_date' => $item->start_date,
            'end_date' => $item->end_date,
            'is_current' => $item->is_current,
            'description' => $item->description,
            'sort_order' => $item->sort_order,
            'author_id' => $item->updated_by ?? $item->created_by,
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
