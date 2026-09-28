<?php

namespace App\Services\People;

use App\Exceptions\DomainException;
use App\Jobs\FetchSocialAvatars;
use App\Models\Person;
use App\Models\User;
use App\Notifications\ProfileChanged;
use App\Services\Access\PersonAccess;
use App\Services\AuditLogger;
use App\Services\Social\SocialProfileFetcher;
use App\Support\SocialNetworks;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * ساخت، ویرایش و حذف اشخاص.
 *
 * داده ورودی قبلاً در FormRequest اعتبارسنجی و یکدست شده است.
 */
class PersonService
{
    /** فیلدهای حساس که فقط با دسترسی ویژه تغییر می‌کنند */
    public const SENSITIVE_FIELDS = ['national_code', 'phone', 'birth_cert_no', 'is_locked', 'password', 'username'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PersonAccess $access,
        private readonly PersonTextService $texts,
    ) {}

    public function create(array $data, User $actor): Person
    {
        return DB::transaction(function () use ($data, $actor) {
            $person = new Person;
            $person->fill(Arr::only($data, (new Person)->getFillable()));
            $person->created_by = $actor->id;
            $person->updated_by = $actor->id;
            $this->applySensitive($person, $data);
            $this->trackEditors($person, $actor);
            $person->save();

            if (! empty($data['password'])) {
                $this->setPassword($person, $data['password'], $actor);
            }
            if (! empty($data['username'])) {
                $this->setUsername($person, $data['username']);
            }
            if (! empty($data['biography'])) {
                $this->texts->save($person, 'biography', $data['biography'], $actor);
            }

            $this->audit->log('person.created', $person, ['name' => $person->fullName()], $actor);

            $networks = array_values(array_intersect(SocialProfileFetcher::FETCHABLE, array_keys((array) ($person->social ?? []))));
            if ($networks && config('pedigree.social.fetch_enabled', true)) {
                defer(fn () => FetchSocialAvatars::dispatchSync($person->id, $networks, $actor->id));
            }

            return $person;
        });
    }

    public function update(Person $person, array $data, User $actor): Person
    {
        $data = $this->guardPrivateFields($person, $data, $actor);

        return DB::transaction(function () use ($person, $data, $actor) {
            $person->fill(Arr::only($data, $person->getFillable()));

            if ($this->access->canManageSensitive($actor, $person)) {
                $this->applySensitive($person, $data);
            }

            // مرد/زن بودن شخصی که فرزند یا همسر دارد قابل تغییر نیست (روابط به هم می‌ریزد)
            if ($person->isDirty('gender') && $this->hasGenderBoundRelations($person)) {
                throw new DomainException('جنسیت شخصی که فرزند یا همسر ثبت‌شده دارد قابل تغییر نیست.');
            }

            // ثبت درگذشتِ عضوی که حساب فعال دارد فقط توسط خودش یا مدیر (جلوگیری از قفل کردن حساب دیگران)
            if ($person->isDirty('is_deceased') && $person->hasActiveAccount() && ! $this->access->canManageSensitive($actor, $person)) {
                throw new DomainException('وضعیت درگذشتِ عضوی که حساب فعال دارد را فقط مدیر می‌تواند تغییر دهد.', 403);
            }

            // اگر شخص فوت کرده، حسابش دیگر قابل ورود نیست
            if ($person->isDirty('is_deceased') && $person->is_deceased) {
                $person->user?->tokens()->delete();
            }

            $changes = $this->audit->diff($person);
            $socialChanged = $this->changedSocialNetworks($person);
            $this->trackEditors($person, $actor);
            $person->updated_by = $actor->id;
            $person->save();

            $sensitive = $this->access->canManageSensitive($actor, $person);
            if (array_key_exists('password', $data) && $data['password'] && $sensitive) {
                $this->setPassword($person, $data['password'], $actor);
                $changes['password'] = ['***', '***'];
            }
            if (array_key_exists('username', $data) && $sensitive && ($data['username'] ?? null) !== $person->user?->username) {
                $this->setUsername($person, $data['username'] ?: null);
                $changes['username'] = [null, $data['username'] ?: null];
            }
            // سازگاری با نسخه‌های قبلی API: زندگی‌نامه از مسیر متن‌های رنگی ذخیره می‌شود
            if (array_key_exists('biography', $data)) {
                $this->texts->save($person, 'biography', $data['biography'], $actor);
            }

            if ($changes) {
                $this->audit->log('person.updated', $person, ['changes' => $changes], $actor);
                $this->notifyOwner($person, $actor, array_keys($changes));
            }

            // عکس پروفایل شبکه‌هایی که شناسه‌شان تازه ثبت شده، پس از پاسخ دریافت می‌شود
            if ($socialChanged) {
                defer(fn () => FetchSocialAvatars::dispatchSync($person->id, $socialChanged, $actor->id));
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

    /** @return string[] شبکه‌های قابل دریافت خودکار که مقدارشان عوض شده */
    private function changedSocialNetworks(Person $person): array
    {
        if (! $person->isDirty('social') || ! config('pedigree.social.fetch_enabled', true)) {
            return [];
        }
        $old = (array) (json_decode((string) $person->getRawOriginal('social'), true) ?: []);
        $new = (array) ($person->social ?? []);
        $changed = [];
        foreach (SocialProfileFetcher::FETCHABLE as $network) {
            if (($new[$network] ?? null) !== null && ($new[$network] ?? null) !== ($old[$network] ?? null)) {
                $changed[] = $network;
            }
        }

        return $changed;
    }

    /**
     * ویرایشگری که شماره/نشانی این شخص را نمی‌بیند (چون خود شخص محدودش کرده) نباید بتواند
     * آن‌ها را تغییر دهد یا ناخواسته پاک کند (فرم او این فیلدها را خالی دارد).
     * تنظیم «چه کسانی ببینند» هم فقط دست خود شخص است.
     */
    private function guardPrivateFields(Person $person, array $data, User $actor): array
    {
        if (! $this->access->canManagePrivacy($actor, $person)) {
            unset($data['contact_visibility'], $data['location_visibility']);
        }
        if (! $this->access->canViewLocation($actor, $person)) {
            unset($data['address'], $data['postal_code'], $data['home_lat'], $data['home_lng']);
        }
        if (! $this->access->canViewContact($actor, $person)) {
            unset($data['email'], $data['landline']);
            if (array_key_exists('social', $data)) {
                // شماره‌های واتس‌اپ/تلگرام قبلی دست‌نخورده می‌مانند و شماره جدید پذیرفته نمی‌شود
                $social = array_filter((array) ($data['social'] ?? []), fn ($v, $k) => ! SocialNetworks::isPhone($k, $v), ARRAY_FILTER_USE_BOTH);
                foreach ((array) ($person->social ?? []) as $network => $value) {
                    if (SocialNetworks::isPhone($network, $value)) {
                        $social[$network] = $value;
                    }
                }
                $data['social'] = $social ?: null;
            }
        }

        return $data;
    }

    /** ساخت یا به‌روزرسانی حساب کاربری شخص با رمز عبور */
    public function setPassword(Person $person, string $password, ?User $actor = null): User
    {
        $user = $person->user ?? new User(['person_id' => $person->id]);
        $user->person_id = $person->id;
        $user->password = $password;
        $user->password_changed_at = now();
        // چه کسی رمز را تعیین کرده (مثلاً پدر یا مادر)؛ با اولین ورود پیامکی صاحب حساب، رمزِ دیگران پاک می‌شود
        $user->password_set_by = $actor?->id;
        $user->save();
        if ($actor === null || $actor->id === $user->id) {
            $user->password_set_by = $user->id;
            $user->save();
        }
        $person->setRelation('user', $user);

        return $user;
    }

    /**
     * نام کاربری برای ورود بدون موبایل/کد ملی (سالمندان). null = حذف نام کاربری.
     * اگر حساب کاربری نباشد ساخته می‌شود ولی تا رمز تعیین نشود قابل ورود نیست.
     */
    public function setUsername(Person $person, ?string $username): ?User
    {
        $user = $person->user;
        if ($username === null) {
            if ($user) {
                $user->username = null;
                $user->save();
            }

            return $user;
        }
        $user ??= new User(['person_id' => $person->id]);
        $user->person_id = $person->id;
        $user->username = $username;
        $user->save();
        $person->setRelation('user', $user);

        return $user;
    }

    /** ثبت آخرین ویرایشگر هر فیلد تغییرکرده (برای نمایش رنگ نویسنده کنار هر مشخصه) */
    private function trackEditors(Person $person, User $actor): void
    {
        $meta = $person->field_meta ?? [];
        $tracked = array_merge($person->getFillable(), ['national_code', 'phone', 'birth_cert_no']);
        foreach (array_keys($person->getDirty()) as $field) {
            $key = match ($field) {
                'national_code_hash', 'phone_hash' => null,
                default => in_array($field, $tracked, true) ? $field : null,
            };
            if ($key !== null) {
                $meta[$key] = ['u' => $actor->id, 't' => now()->getTimestamp()];
            }
        }
        $person->field_meta = $meta ?: null;
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
