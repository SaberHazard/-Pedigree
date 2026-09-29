<?php

namespace App\Services\People;

use App\Exceptions\DomainException;
use App\Http\Requests\PersonRequest;
use App\Models\EditRequest;
use App\Models\Marriage;
use App\Models\Person;
use App\Models\User;
use App\Notifications\EditRequestDecided;
use App\Notifications\EditRequested;
use App\Services\Access\PersonAccess;
use App\Services\AuditLogger;
use App\Services\KinshipDegrees;
use App\Services\Tree\NodePresenter;
use App\Support\Author;
use App\Support\PartialDate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * پیشنهاد ویرایش بستگان درجه دو و سه:
 *  - بستگان درجه یک (و خود شخص) مثل قبل مستقیم ویرایش می‌کنند
 *  - بستگان درجه دو و سه پیشنهاد می‌دهند؛ تا تأیید مدیر در سایت چیزی تغییر نمی‌کند
 *  - اطلاعات حساس (کد ملی، موبایل، نشانی، تنظیم حریم خصوصی) پیشنهادی نیست؛ فقط خود شخص و بستگان درجه یک
 */
class EditRequestService
{
    /** بیشترین درجه خویشاوندی برای پیشنهاد ویرایش */
    public const MAX_DEGREE = 3;

    /** فیلدهایی که بستگان درجه دو و سه می‌توانند پیشنهاد دهند */
    public const PERSON_FIELDS = [
        'first_name', 'last_name', 'nickname', 'title', 'birth_order', 'birth_date', 'birth_place',
        'is_deceased', 'death_date', 'death_place', 'burial_place', 'burial_lat', 'burial_lng',
        'birth_cert_place', 'occupation', 'education', 'residence',
        'education_level', 'education_field', 'education_field_group', 'education_institution', 'academic_rank', 'workplace',
        'country', 'province', 'city', 'website', 'social', 'blood_type', 'languages', 'interests', 'custom_fields',
    ];

    public const MARRIAGE_FIELDS = ['status', 'marriage_date', 'end_date'];

    /** سقف پیشنهادهای در انتظار هر عضو (ضد اسپم) */
    public const MAX_PENDING = 30;

    public function __construct(
        private readonly PersonAccess $access,
        private readonly KinshipDegrees $degrees,
        private readonly PersonService $persons,
        private readonly AuditLogger $audit,
    ) {}

    /** درجه خویشاوندی کاربر با شخص اگر اجازه «پیشنهاد ویرایش» دارد (null = ندارد) */
    public function suggestDegree(?User $user, Person $target): ?int
    {
        if (! $this->access->canSuggest($user, $target)) {
            return null;
        }

        return $this->degrees->relativeDegree($target, $user->person);
    }

    /**
     * پیشنهاد ویرایش مشخصات یک شخص
     *
     * @param  array  $validated  داده اعتبارسنجی‌شده فرم (همان قوانین ویرایش عادی)
     */
    public function suggestPerson(User $user, Person $person, array $validated, ?string $reason = null): EditRequest
    {
        $degree = $this->suggestDegree($user, $person);
        if ($degree === null) {
            throw new DomainException('شما اجازه ویرایش یا پیشنهاد ویرایش این پروفایل را ندارید.', 403);
        }
        $changes = [];
        $original = [];
        foreach (self::PERSON_FIELDS as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $new = $validated[$field];
            $old = $person->getAttribute($field);
            if ($this->same($old, $new)) {
                continue;
            }
            $changes[$field] = $new;
            $original[$field] = $old;
        }

        return $this->store($user, EditRequest::KIND_PERSON, $person, null, $degree, $changes, $original, $reason);
    }

    /** پیشنهاد ویرایش وضعیت و تاریخ‌های یک ازدواج (مثلاً ثبت طلاق و تاریخ آن) */
    public function suggestMarriage(User $user, Marriage $marriage, array $data, ?string $reason = null): EditRequest
    {
        $best = null;
        foreach (array_filter([$marriage->husband, $marriage->wife]) as $spouse) {
            $d = $this->suggestDegree($user, $spouse);
            if ($d !== null && ($best === null || $d < $best[1])) {
                $best = [$spouse, $d];
            }
        }
        if ($best === null) {
            throw new DomainException('شما اجازه ویرایش یا پیشنهاد ویرایش این ازدواج را ندارید.', 403);
        }
        $changes = [];
        $original = [];
        foreach (self::MARRIAGE_FIELDS as $field) {
            if (array_key_exists($field, $data) && ! $this->same($marriage->getAttribute($field), $data[$field])) {
                $changes[$field] = $data[$field];
                $original[$field] = $marriage->getAttribute($field);
            }
        }

        return $this->store($user, EditRequest::KIND_MARRIAGE, $best[0], $marriage, $best[1], $changes, $original, $reason);
    }

    private function store(User $user, string $kind, Person $person, ?Marriage $marriage, int $degree, array $changes, array $original, ?string $reason): EditRequest
    {
        if (! $changes) {
            throw new DomainException('تغییری نسبت به اطلاعات فعلی نیست.');
        }
        if (EditRequest::query()->where('requested_by', $user->id)->where('status', EditRequest::STATUS_PENDING)->count() >= self::MAX_PENDING) {
            throw new DomainException('پیشنهادهای قبلی شما هنوز در انتظار بررسی مدیر است؛ بعد از بررسی آن‌ها دوباره امتحان کنید.', 429);
        }
        $reason = $reason !== null ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $reason) ?? ''), 0, 300) : null;

        $request = EditRequest::create([
            'kind' => $kind,
            'person_id' => $person->id,
            'marriage_id' => $marriage?->id,
            'requested_by' => $user->id,
            'degree' => $degree,
            'changes' => $changes,
            'original' => $original,
            'reason' => $reason ?: null,
        ]);
        $this->audit->log('edit_request.created', $person, ['request' => $request->id, 'fields' => array_keys($changes)], $user);

        // خبر به مدیران (حداکثر هر ۱۰ دقیقه یک بار برای هر پیشنهاددهنده)
        if (Cache::add('edit-req-notify:'.$user->id, 1, 600)) {
            Notification::send(User::query()->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->where('status', User::STATUS_ACTIVE)->get(), new EditRequested($request));
        }

        return $request;
    }

    /** تأیید و اعمال پیشنهاد */
    public function approve(EditRequest $request, User $admin): void
    {
        DB::transaction(function () use ($request, $admin) {
            $locked = EditRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== EditRequest::STATUS_PENDING) {
                throw new DomainException('این پیشنهاد قبلاً بررسی شده است.');
            }
            if ($locked->kind === EditRequest::KIND_MARRIAGE) {
                $marriage = Marriage::query()->find($locked->marriage_id);
                if (! $marriage) {
                    throw new DomainException('این ازدواج دیگر وجود ندارد.');
                }
                $data = array_intersect_key($locked->changes, array_flip(self::MARRIAGE_FIELDS));
                foreach (['marriage_date', 'end_date'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $data[$field] = PartialDate::normalize($data[$field]);
                    }
                }
                $marriage->fill($data);
                $changes = $this->audit->diff($marriage);
                $marriage->save();
                $this->audit->log('marriage.updated', $marriage, ['changes' => $changes, 'suggested_by' => $locked->requested_by], $admin);
            } else {
                $person = Person::query()->find($locked->person_id);
                if (! $person) {
                    throw new DomainException('این پروفایل دیگر وجود ندارد.');
                }
                $this->persons->update($person, array_intersect_key($locked->changes, array_flip(self::PERSON_FIELDS)), $admin);
            }
            $locked->forceFill(['status' => EditRequest::STATUS_APPROVED, 'decided_by' => $admin->id, 'decided_at' => now()])->save();
            $this->audit->log('edit_request.approved', $locked->person, ['request' => $locked->id, 'suggested_by' => $locked->requested_by], $admin);
            $locked->requester?->notify(new EditRequestDecided($locked));
        });
    }

    public function reject(EditRequest $request, User $admin, ?string $note = null): void
    {
        DB::transaction(function () use ($request, $admin, $note) {
            $locked = EditRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== EditRequest::STATUS_PENDING) {
                throw new DomainException('این پیشنهاد قبلاً بررسی شده است.');
            }
            $locked->forceFill([
                'status' => EditRequest::STATUS_REJECTED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note ? mb_substr(trim($note), 0, 300) : null,
            ])->save();
            $this->audit->log('edit_request.rejected', $locked->person, ['request' => $locked->id], $admin);
            $locked->requester?->notify(new EditRequestDecided($locked));
        });
    }

    /** نمایش پیشنهاد برای پنل مدیریت و خود پیشنهاددهنده */
    public function present(EditRequest $r): array
    {
        $labels = PersonRequest::attributeNames() + ['status' => 'وضعیت ازدواج', 'marriage_date' => 'تاریخ ازدواج', 'end_date' => 'تاریخ پایان (طلاق یا فوت همسر)', 'is_deceased' => 'درگذشته'];
        $current = $r->kind === EditRequest::KIND_MARRIAGE ? $r->marriage : $r->person;
        $fields = [];
        foreach ($r->changes as $field => $value) {
            $now = $current?->getAttribute($field);
            $fields[] = [
                'field' => $field,
                'label' => $labels[$field] ?? $field,
                'old' => $this->display($field, $r->original[$field] ?? null),
                'new' => $this->display($field, $value),
                // مقدار فعلی بعد از پیشنهاد عوض شده است؟
                'stale' => $r->status === EditRequest::STATUS_PENDING && ! $this->same($now, $r->original[$field] ?? null),
            ];
        }

        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'status' => $r->status,
            'degree' => $r->degree,
            'reason' => $r->reason,
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'person' => $r->person ? NodePresenter::person($r->person) : null,
            'marriage' => $r->marriage ? NodePresenter::marriage($r->marriage) : null,
            'requester' => Author::of($r->requester),
            'decider' => $r->decider?->displayName(),
            'fields' => $fields,
        ];
    }

    /** مقایسه مقدار فعلی و پیشنهادی بدون حساسیت به شکل ارسال فرم ('' و null، عدد و رشته، ترتیب کلیدها) */
    private function same(mixed $a, mixed $b): bool
    {
        $clean = function ($v) use (&$clean) {
            if (is_array($v)) {
                $v = array_filter(array_map($clean, $v), fn ($x) => $x !== null && $x !== [] && $x !== '');
                if (! array_is_list($v)) {
                    ksort($v);
                }

                return $v ?: null;
            }

            return $v === '' ? null : $v;
        };
        $a = $clean($a);
        $b = $clean($b);
        if (is_numeric($a) && is_numeric($b) && ! is_bool($a) && ! is_bool($b)) {
            return abs((float) $a - (float) $b) < 1e-7;
        }
        $norm = fn ($v) => is_bool($v) ? (string) (int) $v : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v === null ? null : (string) $v));

        return $norm($a) === $norm($b);
    }

    private function display(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if ($field === 'status') {
            return ['married' => 'متأهل', 'divorced' => 'جدا شده (طلاق)', 'widowed' => 'فوت همسر'][$value] ?? (string) $value;
        }
        if ($field === 'is_deceased') {
            return $value ? 'بله' : 'خیر';
        }
        if ($field === 'social' && is_array($value)) {
            return implode('، ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($value), $value));
        }
        if ($field === 'custom_fields' && is_array($value)) {
            return implode('، ', array_map(fn ($row) => ($row['label'] ?? '').': '.($row['value'] ?? ''), $value));
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
    }
}
