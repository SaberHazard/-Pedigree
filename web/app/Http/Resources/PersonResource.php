<?php

namespace App\Http\Resources;

use App\Models\Person;
use App\Services\Access\PersonAccess;
use App\Services\Tree\NodePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * نمایش کامل اطلاعات یک شخص.
 * فیلدهای حساس فقط برای کسی که دسترسی دارد پر می‌شوند و برای بقیه null هستند.
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
        $sensitive = $access->canViewSensitive($user, $person);
        $account = $person->user;
        $avatar = $person->avatar;

        return NodePresenter::person($person) + [
            'avatar_medium' => $avatar && $avatar->isApproved() ? $avatar->url('medium') : null,
            'avatar_media_id' => $person->avatar_media_id,
            'death_place' => $person->death_place,
            'burial_place' => $person->burial_place,
            'education' => $person->education,
            'residence' => $person->residence,
            'biography' => $person->biography,
            'is_locked' => $person->is_locked,

            // اطلاعات حساس
            'national_code' => $sensitive ? $person->national_code : null,
            'phone' => $sensitive ? $person->phone : null,
            'birth_cert_no' => $sensitive ? $person->birth_cert_no : null,
            'birth_cert_place' => $sensitive ? $person->birth_cert_place : null,
            'email' => $sensitive ? $person->email : null,
            'has_national_code' => $person->national_code_hash !== null,
            'has_phone' => $person->phone_hash !== null,

            'account' => [
                'exists' => $account !== null,
                'active' => $person->hasActiveAccount(),
                'has_password' => $sensitive ? ($account?->password !== null) : null,
            ],

            'created_at' => $person->created_at?->toIso8601String(),
            'updated_at' => $person->updated_at?->toIso8601String(),
            'created_by' => $person->creator?->displayName(),
            'permissions' => $access->summary($user, $person),
        ];
    }
}
