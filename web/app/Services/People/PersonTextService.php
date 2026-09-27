<?php

namespace App\Services\People;

use App\Exceptions\DomainException;
use App\Models\Person;
use App\Models\PersonText;
use App\Models\PersonTextRevision;
use App\Models\User;
use App\Notifications\ProfileChanged;
use App\Services\AuditLogger;
use App\Support\AttributedText;
use App\Support\PersianText;
use Illuminate\Support\Facades\DB;

/**
 * ذخیره متن‌های بلند پروفایل با ثبت نویسنده هر کلمه و نگهداری همه نسخه‌ها.
 */
class PersonTextService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** فیلدهای مجاز: summary, description, biography, resume */
    public static function fields(): array
    {
        return array_keys(config('pedigree.profile.texts', []));
    }

    /**
     * ذخیره نسخه جدید.
     *
     * @param  ?int  $baseRevision  نسخه‌ای که ویرایشگر روی آن کار کرده؛ اگر در این فاصله کس دیگری
     *                              متن را تغییر داده باشد خطای 409 برمی‌گردد تا تغییرات او از بین نرود.
     */
    public function save(Person $person, string $field, ?string $text, User $actor, ?int $baseRevision = null): PersonText
    {
        $this->assertField($field);
        $plain = PersianText::normalizeMultiline($text);
        $max = (int) config("pedigree.profile.texts.{$field}.max", 20000);
        if (mb_strlen($plain) > $max) {
            throw new DomainException('متن طولانی‌تر از حد مجاز ('.PersianText::toPersianDigits((string) $max).' حرف) است.');
        }

        $result = DB::transaction(function () use ($person, $field, $plain, $actor, $baseRevision) {
            $record = PersonText::where('person_id', $person->id)->where('field', $field)->lockForUpdate()->first();

            if ($record && $baseRevision !== null && $baseRevision !== $record->revision) {
                throw new DomainException('در این فاصله شخص دیگری این متن را ویرایش کرده است. متن تازه را ببینید و دوباره تغییر دهید.', 409, 'text_conflict');
            }
            if (! $record && $plain === '') {
                return null;
            }
            if ($record && $record->plain === $plain) {
                return [$record, false];
            }

            $applied = AttributedText::apply($record?->cleanSegments() ?? [], $plain, $actor->id);
            $record ??= new PersonText(['person_id' => $person->id, 'field' => $field]);
            $record->segments = $applied['segments'];
            $record->plain = $plain;
            $record->revision = $record->exists ? $record->revision + 1 : 1;
            $record->updated_by = $actor->id;
            $record->save();

            $this->storeRevision($record, $actor, $applied['added'], $applied['removed']);
            $this->syncPersonColumn($person, $field, $plain);
            $this->audit->log('text.updated', $person, [
                'field' => $field,
                'revision' => $record->revision,
                'added' => $applied['added'],
                'removed' => $applied['removed'],
            ], $actor);

            return [$record, true];
        });

        if ($result === null) {
            return new PersonText(['person_id' => $person->id, 'field' => $field]);
        }
        [$record, $changed] = $result;
        if ($changed) {
            $this->notifyOwner($person, $actor, $field);
        }

        return $record;
    }

    /** بازگردانی یک نسخه قبلی (نویسندگان همان نسخه حفظ می‌شوند) */
    public function restore(PersonText $record, int $revision, User $actor): PersonText
    {
        return DB::transaction(function () use ($record, $revision, $actor) {
            $record = PersonText::lockForUpdate()->findOrFail($record->id);
            $old = PersonTextRevision::where('person_text_id', $record->id)->where('revision', $revision)->firstOrFail();
            $segments = AttributedText::sanitize($old->segments);
            $plain = AttributedText::plain($segments);
            if ($plain === $record->plain) {
                return $record;
            }

            $record->segments = $segments;
            $record->plain = $plain;
            $record->revision++;
            $record->updated_by = $actor->id;
            $record->save();

            $this->storeRevision($record, $actor, 0, 0, $revision);
            $person = $record->person()->first();
            if ($person) {
                $this->syncPersonColumn($person, $record->field, $plain);
                $this->audit->log('text.restored', $person, ['field' => $record->field, 'revision' => $record->revision, 'restored_from' => $revision], $actor);
            }

            return $record;
        });
    }

    public function assertField(string $field): void
    {
        if (! in_array($field, self::fields(), true)) {
            throw new DomainException('بخش متنی نامعتبر است.', 404);
        }
    }

    private function storeRevision(PersonText $record, User $actor, int $added, int $removed, ?int $restoredFrom = null): void
    {
        $revision = new PersonTextRevision;
        $revision->person_text_id = $record->id;
        $revision->revision = $record->revision;
        $revision->segments = $record->segments;
        $revision->user_id = $actor->id;
        $revision->added = $added;
        $revision->removed = $removed;
        $revision->restored_from = $restoredFrom;
        $revision->created_at = now();
        $revision->save();
    }

    /** زندگی‌نامه به صورت متن ساده در جدول اشخاص هم نگه داشته می‌شود (برای خروجی GEDCOM) */
    private function syncPersonColumn(Person $person, string $field, string $plain): void
    {
        if ($field === 'biography') {
            Person::whereKey($person->id)->update(['biography' => $plain === '' ? null : $plain]);
            $person->biography = $plain === '' ? null : $plain;
            $person->syncOriginalAttribute('biography');
        }
    }

    private function notifyOwner(Person $person, User $actor, string $field): void
    {
        $owner = $person->user;
        if ($owner && $owner->id !== $actor->id && $owner->isActive() && $owner->last_login_at) {
            $owner->notify(new ProfileChanged($person, $actor, [$field]));
        }
    }
}
