<?php

namespace App\Services\Tree;

use App\Models\Marriage;
use App\Models\Person;

/**
 * تبدیل اشخاص و ازدواج‌ها به آرایه‌های سبک برای رسم درخت.
 *
 * عمداً از API Resource استفاده نشده چون درخت ممکن است هزاران گره داشته باشد
 * و این روش سریع‌تر و کم‌حجم‌تر است. همین قالب را اپ اندروید/iOS هم دریافت می‌کند.
 */
class NodePresenter
{
    public static function person(Person $p): array
    {
        $avatar = $p->relationLoaded('avatar') ? $p->avatar : null;

        return [
            'id' => $p->id,
            'code' => $p->code,
            'first_name' => $p->first_name,
            'last_name' => $p->last_name,
            'nickname' => $p->nickname,
            'title' => $p->title,
            // عنوان خودکار «دکتر/مهندس» و عنوان کامل مرتب‌شده (برای نام روی دایره و چاپ)
            'honorific' => $p->honorific(),
            'display_title' => $p->displayTitle(),
            'gender' => $p->gender,
            'father_id' => $p->father_id,
            'mother_id' => $p->mother_id,
            'birth_order' => $p->birth_order,
            'birth_date' => $p->birth_date,
            'birth_place' => $p->birth_place,
            'is_deceased' => $p->is_deceased,
            'death_date' => $p->death_date,
            'occupation' => $p->occupation,
            'education_level' => $p->education_level,
            'city' => $p->city,
            'avatar' => $avatar && $avatar->isApproved() ? $avatar->url('thumb') : null,
            'has_parents' => $p->father_id !== null || $p->mother_id !== null,
        ];
    }

    public static function marriage(Marriage $m): array
    {
        return [
            'id' => $m->id,
            'husband_id' => $m->husband_id,
            'wife_id' => $m->wife_id,
            'status' => $m->status,
            'marriage_date' => $m->marriage_date,
            'end_date' => $m->end_date,
            'sort_order' => $m->sort_order,
        ];
    }
}
