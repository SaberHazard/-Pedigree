<?php

namespace App\Services\People;

use App\Exceptions\DomainException;
use App\Models\Family;
use App\Models\LinkRequest;
use App\Models\Marriage;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * ادغام دو پروفایل تکراری (فقط مدیر).
 *
 * وقتی دو شاخه جداگانه ساخته شده و یک نفر دو بار ثبت شده، همه روابط، عکس‌ها
 * و اطلاعاتِ «duplicate» به «keep» منتقل و duplicate حذف (نرم) می‌شود.
 */
class MergeService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function merge(Person $keep, Person $duplicate, User $actor): Person
    {
        if ($keep->id === $duplicate->id) {
            throw new DomainException('یک شخص را نمی‌توان با خودش ادغام کرد.');
        }
        if ($keep->gender !== $duplicate->gender) {
            throw new DomainException('جنسیت دو پروفایل یکسان نیست.');
        }
        if ($keep->user && $duplicate->user) {
            throw new DomainException('هر دو پروفایل حساب کاربری دارند؛ ابتدا یکی از حساب‌ها را حذف یا مسدود کنید.');
        }

        return DB::transaction(function () use ($keep, $duplicate, $actor) {
            // ۱. اطلاعات خالیِ keep از duplicate پر می‌شود
            foreach ($keep->getFillable() as $field) {
                if (($keep->{$field} === null || $keep->{$field} === '') && $duplicate->{$field} !== null) {
                    $keep->{$field} = $duplicate->{$field};
                }
            }
            foreach (['father_id', 'mother_id', 'avatar_media_id'] as $field) {
                if (! $keep->{$field} && $duplicate->{$field} && $duplicate->{$field} !== $keep->id) {
                    $keep->{$field} = $duplicate->{$field};
                }
            }
            if ($keep->avatar_media_id !== null && $keep->avatar_media_id === $duplicate->avatar_media_id) {
                $keep->avatar_source = $duplicate->avatar_source;
            }
            // عکس‌های شبکه‌های اجتماعی (رسانه‌ها در ادامه به keep منتقل می‌شوند)
            $keep->social_avatars = ((array) ($keep->social_avatars ?? [])) + ((array) ($duplicate->social_avatars ?? [])) ?: null;

            // اطلاعات حساس: ابتدا از duplicate پاک می‌شود تا ایندکس یکتا خطا ندهد
            $sensitive = [];
            foreach (['national_code', 'phone', 'birth_cert_no'] as $field) {
                if (! $keep->{$field} && $duplicate->{$field}) {
                    $sensitive[$field] = $duplicate->{$field};
                }
            }
            $duplicate->national_code = null;
            $duplicate->phone = null;
            $duplicate->saveQuietly();
            foreach ($sensitive as $field => $value) {
                $keep->{$field} = $value;
            }
            $keep->save();

            // ۲. فرزندان
            Person::where('father_id', $duplicate->id)->update(['father_id' => $keep->id]);
            Person::where('mother_id', $duplicate->id)->update(['mother_id' => $keep->id]);

            // ۳. ازدواج‌ها
            $role = $keep->isMale() ? 'husband_id' : 'wife_id';
            $partnerRole = $keep->isMale() ? 'wife_id' : 'husband_id';
            foreach (Marriage::where($role, $duplicate->id)->get() as $marriage) {
                $exists = Marriage::where($role, $keep->id)->where($partnerRole, $marriage->{$partnerRole})->exists();
                if ($exists) {
                    $marriage->delete();
                } else {
                    $marriage->{$role} = $keep->id;
                    $marriage->save();
                }
            }

            // ۴. رسانه، خاندان‌ها، درخواست‌ها، حساب کاربری
            Media::withTrashed()->where('person_id', $duplicate->id)->update(['person_id' => $keep->id]);
            Family::where('root_person_id', $duplicate->id)->update(['root_person_id' => $keep->id]);
            LinkRequest::where('subject_id', $duplicate->id)->update(['subject_id' => $keep->id]);
            LinkRequest::where('target_id', $duplicate->id)->update(['target_id' => $keep->id]);
            if ($duplicate->user) {
                $duplicate->user->person_id = $keep->id;
                $duplicate->user->save();
            }

            $duplicate->father_id = null;
            $duplicate->mother_id = null;
            $duplicate->avatar_media_id = null;
            $duplicate->saveQuietly();
            $duplicate->delete();

            $this->audit->log('person.merged', $keep, ['merged' => $duplicate->id, 'name' => $duplicate->fullName()], $actor);

            return $keep->refresh();
        });
    }
}
