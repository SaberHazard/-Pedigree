<?php

namespace App\Http\Resources;

use App\Models\Person;
use App\Models\PersonText;
use App\Models\ResumeItem;
use App\Services\Access\PersonAccess;
use App\Services\People\ProfileService;
use App\Services\Tree\NodePresenter;
use App\Support\AttributedText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * نمایش کامل اطلاعات یک شخص.
 *
 * - فیلدهای حساس (کد ملی، شناسنامه) فقط برای کسی که دسترسی دارد پر می‌شوند و برای بقیه null هستند.
 * - نشانی و موقعیت خانه، و راه‌های ارتباطی طبق اجازه خود شخص (share_location / share_contact).
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
            'social' => (object) ($person->social ?? []),
            'biography' => $person->biography,
            'is_locked' => $person->is_locked,

            // نشانی و موقعیت خانه (با اجازه خود شخص یا برای ویرایشگران)
            'address' => $location ? $person->address : null,
            'postal_code' => $location ? $person->postal_code : null,
            'home_location' => $location ? $person->homeLocation() : null,
            'share_location' => $person->share_location,
            'has_home_location' => $person->home_lat !== null,

            // راه‌های ارتباطی
            'phone' => $contact ? $person->phone : null,
            'email' => $contact ? $person->email : null,
            'landline' => $contact ? $person->landline : null,
            'share_contact' => $person->share_contact,

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
