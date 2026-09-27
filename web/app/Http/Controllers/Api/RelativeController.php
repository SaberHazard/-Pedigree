<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PersonRequest;
use App\Http\Resources\LinkRequestResource;
use App\Http\Resources\PersonResource;
use App\Models\Marriage;
use App\Models\Person;
use App\Rules\PartialDateRule;
use App\Services\AuditLogger;
use App\Services\People\LinkService;
use App\Services\People\RelativeService;
use App\Support\PartialDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * افزودن بستگان جدید، اتصال اشخاص موجود و قطع ارتباط والدین
 */
class RelativeController extends Controller
{
    /**
     * افزودن بستگان جدید.
     * بدنه: type (child|spouse|father|mother|sibling) + فیلدهای شخص جدید
     *       + other_parent_id (برای فرزند) + marriage_status/marriage_date (برای همسر)
     */
    public function store(Request $request, Person $person, RelativeService $relatives): JsonResponse
    {
        Gate::authorize('update', $person);

        $input = array_merge($request->all(), PersonRequest::normalizeInput($request->all()));
        if (isset($input['marriage_date']) && is_string($input['marriage_date'])) {
            $input['marriage_date'] = PartialDate::normalize($input['marriage_date']) ?? $input['marriage_date'];
        }
        $type = (string) ($input['type'] ?? '');
        $genderRequired = in_array($type, ['child', 'sibling'], true);

        $validator = Validator::make($input, array_merge(
            PersonRequest::personRules(null, $genderRequired),
            [
                'type' => ['required', Rule::in(RelativeService::TYPES)],
                'other_parent_id' => ['nullable', 'uuid', 'exists:persons,id'],
                'marriage_status' => ['nullable', Rule::in(Marriage::STATUSES)],
                'marriage_date' => ['nullable', new PartialDateRule],
            ]
        ), [], PersonRequest::attributeNames());
        $validator->after(fn ($v) => PersonRequest::afterValidation($v, $input, null));
        $data = $validator->validate();

        $personData = collect($data)->except(['type', 'other_parent_id', 'marriage_status', 'marriage_date'])->all();
        $relative = $relatives->add($person, $type, $personData, $request->user(), [
            'other_parent_id' => $data['other_parent_id'] ?? null,
            'marriage' => array_filter([
                'status' => $data['marriage_status'] ?? null,
                'marriage_date' => $data['marriage_date'] ?? null,
            ]),
        ]);

        return (new PersonResource($relative->load(['avatar', 'user'])))->response()->setStatusCode(201);
    }

    /**
     * اتصال یک شخص موجود (مثلاً همسری که در درخت دیگری ثبت شده)
     * بدنه: type (spouse|father|mother|child), target_id, marriage_status, marriage_date, message
     */
    public function link(Request $request, Person $person, LinkService $links): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['spouse', 'father', 'mother', 'child'])],
            'target_id' => ['required', 'uuid', 'exists:persons,id'],
            'marriage_status' => ['nullable', Rule::in(Marriage::STATUSES)],
            'marriage_date' => ['nullable', new PartialDateRule],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $target = Person::findOrFail($data['target_id']);
        $payload = array_filter([
            'status' => $data['marriage_status'] ?? null,
            'marriage_date' => PartialDate::normalize($data['marriage_date'] ?? null),
        ]);

        $result = $links->link($person, $data['type'], $target, $request->user(), $payload, $data['message'] ?? null);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['status'] === 'linked'
                ? 'اتصال با موفقیت انجام شد.'
                : 'درخواست اتصال ثبت شد و پس از تأیید بستگانِ طرف مقابل انجام می‌شود.',
            'request' => isset($result['request']) ? new LinkRequestResource($result['request']->load(['subject', 'target'])) : null,
        ], $result['status'] === 'linked' ? 200 : 202);
    }

    /** قطع ارتباط با پدر یا مادر (در صورت ثبت اشتباه) */
    public function unlinkParent(Request $request, Person $person, string $role, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $person);
        abort_unless(in_array($role, ['father', 'mother'], true), 404);

        $field = $role.'_id';
        $old = $person->{$field};
        $person->{$field} = null;
        $person->save();
        $audit->log('relation.parent_removed', $person, [$field => [$old, null]], $request->user());

        return response()->json(['message' => 'ارتباط حذف شد.']);
    }
}
