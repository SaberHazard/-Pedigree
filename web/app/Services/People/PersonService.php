<?php

namespace App\Services\People;

use App\Exceptions\DomainException;
use App\Models\Person;
use App\Models\User;
use App\Notifications\ProfileChanged;
use App\Services\Access\PersonAccess;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * ساخت، ویرایش و حذف اشخاص.
 *
 * داده ورودی قبلاً در FormRequest اعتبارسنجی و یکدست شده است.
 */
class PersonService
{
    /** فیلدهای حساس که فقط با دسترسی ویژه تغییر می‌کنند */
    public const SENSITIVE_FIELDS = ['national_code', 'phone', 'birth_cert_no', 'is_locked', 'password'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PersonAccess $access,
    ) {}

    public function create(array $data, User $actor): Person
    {
        return DB::transaction(function () use ($data, $actor) {
            $person = new Person;
            $person->fill(Arr::only($data, (new Person)->getFillable()));
            $person->created_by = $actor->id;
            $person->updated_by = $actor->id;
            $this->applySensitive($person, $data);
            $person->save();

            if (! empty($data['password'])) {
                $this->setPassword($person, $data['password']);
            }

            $this->audit->log('person.created', $person, ['name' => $person->fullName()], $actor);

            return $person;
        });
    }

    public function update(Person $person, array $data, User $actor): Person
    {
        return DB::transaction(function () use ($person, $data, $actor) {
            $person->fill(Arr::only($data, $person->getFillable()));

            if ($this->access->canManageSensitive($actor, $person)) {
                $this->applySensitive($person, $data);
            }

            // مرد/زن بودن شخصی که فرزند یا همسر دارد قابل تغییر نیست (روابط به هم می‌ریزد)
            if ($person->isDirty('gender') && $this->hasGenderBoundRelations($person)) {
                throw new DomainException('جنسیت شخصی که فرزند یا همسر ثبت‌شده دارد قابل تغییر نیست.');
            }

            // اگر شخص فوت کرده، حسابش دیگر قابل ورود نیست
            if ($person->isDirty('is_deceased') && $person->is_deceased) {
                $person->user?->tokens()->delete();
            }

            $changes = $this->audit->diff($person);
            $person->updated_by = $actor->id;
            $person->save();

            if (array_key_exists('password', $data) && $data['password'] && $this->access->canManageSensitive($actor, $person)) {
                $this->setPassword($person, $data['password']);
                $changes['password'] = ['***', '***'];
            }

            if ($changes) {
                $this->audit->log('person.updated', $person, ['changes' => $changes], $actor);
                $this->notifyOwner($person, $actor, array_keys($changes));
            }

            return $person->refresh();
        });
    }

    public function delete(Person $person, User $actor): void
    {
        DB::transaction(function () use ($person, $actor) {
            // حساب کاربری شخص حذف‌شده مسدود می‌شود
            if ($user = $person->user) {
                $user->status = User::STATUS_BLOCKED;
                $user->save();
                $user->tokens()->delete();
            }
            $person->delete();
            $this->audit->log('person.deleted', $person, ['name' => $person->fullName()], $actor);
        });
    }

    public function restore(Person $person, User $actor): void
    {
        $person->restore();
        $this->audit->log('person.restored', $person, ['name' => $person->fullName()], $actor);
    }

    /** ساخت یا به‌روزرسانی حساب کاربری شخص با رمز عبور */
    public function setPassword(Person $person, string $password): User
    {
        $user = $person->user ?? new User(['person_id' => $person->id]);
        $user->person_id = $person->id;
        $user->password = $password;
        $user->password_changed_at = now();
        $user->save();
        $person->setRelation('user', $user);

        return $user;
    }

    private function applySensitive(Person $person, array $data): void
    {
        foreach (['national_code', 'phone', 'birth_cert_no'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field] === '' ? null : $data[$field];
                if ($value !== $person->{$field}) {
                    $person->{$field} = $value;
                }
            }
        }
        if (array_key_exists('is_locked', $data)) {
            $person->is_locked = (bool) $data['is_locked'];
        }
    }

    private function hasGenderBoundRelations(Person $person): bool
    {
        return $person->childrenQuery()->exists() || $person->marriages()->exists();
    }

    /** اگر کس دیگری پروفایل شخص را ویرایش کرد، به خودش اطلاع بده */
    private function notifyOwner(Person $person, User $actor, array $fields): void
    {
        $owner = $person->user;
        if ($owner && $owner->id !== $actor->id && $owner->isActive() && $owner->last_login_at) {
            $owner->notify(new ProfileChanged($person, $actor, $fields));
        }
    }
}
