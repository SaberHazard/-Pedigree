<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Models\PersonText;
use App\Models\PersonTextRevision;
use App\Models\ResumeItem;
use App\Rules\PartialDateRule;
use App\Services\AuditLogger;
use App\Services\People\PersonTextService;
use App\Services\People\ProfileService;
use App\Support\AttributedText;
use App\Support\PartialDate;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * متن‌های رنگی پروفایل (بیوگرافی، توضیحات بستگان، زندگی‌نامه، رزومه) و سوابق رزومه.
 *
 * ویرایش: خود شخص و بستگان درجه یک (قوانین PersonAccess::canEdit).
 * مشاهده نسخه‌ها: همه اعضا (هر تغییر با نام نویسنده ثبت و قابل دیدن است).
 */
class ProfileTextController extends Controller
{
    public function __construct(
        private readonly PersonTextService $texts,
        private readonly ProfileService $profile,
        private readonly AuditLogger $audit,
    ) {}

    /** ذخیره متن (با base_revision برای جلوگیری از پاک شدن تغییرات هم‌زمان دیگران) */
    public function update(Request $request, Person $person, string $field): JsonResponse
    {
        Gate::authorize('update', $person);
        $this->texts->assertField($field);
        $data = $request->validate([
            'text' => ['present', 'nullable', 'string', 'max:'.((int) config("pedigree.profile.texts.{$field}.max", 20000) * 2)],
            'base_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        $record = $this->texts->save($person, $field, $data['text'], $request->user(), $data['base_revision'] ?? null);

        return $this->textResponse($person, $field, $record);
    }

    /** فهرست نسخه‌های یک متن */
    public function revisions(Person $person, string $field): JsonResponse
    {
        Gate::authorize('viewHistory', $person);
        $this->texts->assertField($field);
        $record = PersonText::where('person_id', $person->id)->where('field', $field)->first();
        if (! $record) {
            return response()->json(['data' => []]);
        }

        $revisions = PersonTextRevision::with('user.person')
            ->where('person_text_id', $record->id)
            ->orderByDesc('revision')
            ->limit(200)
            ->get();

        return response()->json(['data' => $revisions->map(fn (PersonTextRevision $r) => [
            'revision' => $r->revision,
            'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->displayName()] : null,
            'added' => $r->added,
            'removed' => $r->removed,
            'restored_from' => $r->restored_from,
            'length' => mb_strlen(AttributedText::plain(AttributedText::sanitize($r->segments))),
            'created_at' => $r->created_at?->toIso8601String(),
            'current' => $r->revision === $record->revision,
        ])]);
    }

    /** متن کامل یک نسخه (برای پیش‌نمایش پیش از بازگردانی) */
    public function showRevision(Person $person, string $field, int $revision): JsonResponse
    {
        Gate::authorize('viewHistory', $person);
        $this->texts->assertField($field);
        $record = PersonText::where('person_id', $person->id)->where('field', $field)->firstOrFail();
        $row = PersonTextRevision::where('person_text_id', $record->id)->where('revision', $revision)->firstOrFail();
        $segments = AttributedText::sanitize($row->segments);

        return response()->json([
            'revision' => $row->revision,
            'segments' => $segments,
            'contributors' => $this->profile->contributors($person, AttributedText::authors($segments)),
        ]);
    }

    public function restore(Request $request, Person $person, string $field, int $revision): JsonResponse
    {
        Gate::authorize('update', $person);
        $this->texts->assertField($field);
        $record = PersonText::where('person_id', $person->id)->where('field', $field)->firstOrFail();
        $record = $this->texts->restore($record, $revision, $request->user());

        return $this->textResponse($person, $field, $record);
    }

    // ------------------------------------------------------------------ رزومه

    public function storeResume(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('update', $person);
        $data = $this->validateResume($request);

        $item = new ResumeItem($data);
        $item->person_id = $person->id;
        $item->created_by = $request->user()->id;
        $item->updated_by = $request->user()->id;
        $item->save();
        $this->audit->log('resume.created', $person, ['type' => $item->type, 'title' => $item->title], $request->user());

        return response()->json(['data' => PersonResource::resumeItem($item), 'contributors' => $this->contributors($person)], 201);
    }

    public function updateResume(Request $request, ResumeItem $item): JsonResponse
    {
        $person = $item->person()->firstOrFail();
        Gate::authorize('update', $person);
        $data = $this->validateResume($request, false);

        $item->fill($data);
        if ($item->isDirty()) {
            $changes = [];
            foreach (array_keys($item->getDirty()) as $key) {
                $changes[$key] = [$item->getOriginal($key), $item->getAttribute($key)];
            }
            $item->updated_by = $request->user()->id;
            $item->save();
            $this->audit->log('resume.updated', $person, ['title' => $item->title, 'changes' => $changes], $request->user());
        }

        return response()->json(['data' => PersonResource::resumeItem($item), 'contributors' => $this->contributors($person)]);
    }

    public function destroyResume(Request $request, ResumeItem $item): JsonResponse
    {
        $person = $item->person()->firstOrFail();
        Gate::authorize('update', $person);
        $item->delete();
        // متن کامل مورد حذف‌شده در تاریخچه می‌ماند
        $this->audit->log('resume.deleted', $person, [
            'type' => $item->type, 'title' => $item->title, 'organization' => $item->organization,
            'start_date' => $item->start_date, 'end_date' => $item->end_date,
        ], $request->user());

        return response()->json(['message' => 'حذف شد.']);
    }

    private function validateResume(Request $request, bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';
        foreach (['start_date', 'end_date'] as $key) {
            if (is_string($request->input($key)) && trim($request->input($key)) !== '') {
                $request->merge([$key => PartialDate::normalize($request->input($key)) ?? $request->input($key)]);
            }
        }
        $data = $request->validate([
            'type' => [$req, Rule::in(array_keys(config('pedigree.profile.resume_types', [])))],
            'title' => [$req, 'string', 'max:200'],
            'organization' => ['nullable', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:200'],
            'start_date' => ['nullable', new PartialDateRule],
            'end_date' => ['nullable', new PartialDateRule],
            'is_current' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ], [], ['title' => 'عنوان', 'organization' => 'سازمان / محل', 'start_date' => 'تاریخ شروع', 'end_date' => 'تاریخ پایان']);

        foreach (['title', 'organization', 'location'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = PersianText::normalize($data[$key]) ?: null;
            }
        }
        if (array_key_exists('description', $data)) {
            $data['description'] = PersianText::normalizeMultiline($data['description']) ?: null;
        }
        if (! empty($data['is_current'])) {
            $data['end_date'] = null;
        }

        return $data;
    }

    private function textResponse(Person $person, string $field, PersonText $record): JsonResponse
    {
        return response()->json([
            'data' => [
                'field' => $field,
                'segments' => $record->exists ? $record->cleanSegments() : [],
                'revision' => $record->exists ? $record->revision : 0,
                'updated_at' => $record->updated_at?->toIso8601String(),
            ],
            'contributors' => $this->contributors($person),
        ]);
    }

    private function contributors(Person $person): array
    {
        return $this->profile->contributors($person, $this->profile->authorIds($person));
    }
}
