<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EditRequest;
use App\Models\Marriage;
use App\Models\Person;
use App\Rules\PartialDateRule;
use App\Services\Access\PersonAccess;
use App\Services\AuditLogger;
use App\Services\People\EditRequestService;
use App\Services\Tree\NodePresenter;
use App\Support\Author;
use App\Support\PartialDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ویرایش و حذف ازدواج‌ها
 */
class MarriageController extends Controller
{
    public function __construct(
        private readonly PersonAccess $access,
        private readonly AuditLogger $audit,
        private readonly EditRequestService $edits,
    ) {}

    /** جزئیات یک ازدواج (با کلیک روی قلب در درخت): تاریخ ازدواج، طلاق و اجازه‌ها */
    public function show(Request $request, Marriage $marriage): JsonResponse
    {
        $user = $request->user();
        $husband = $marriage->husband()->with('avatar')->first();
        $wife = $marriage->wife()->with('avatar')->first();
        $canEdit = $this->access->canEditMarriage($user, $marriage);

        return response()->json(['data' => [
            'marriage' => NodePresenter::marriage($marriage),
            'husband' => $husband ? NodePresenter::person($husband) : null,
            'wife' => $wife ? NodePresenter::person($wife) : null,
            'creator' => Author::of($marriage->creator),
            'can_edit' => $canEdit,
            // بستگان درجه دو و سه: پیشنهاد ویرایش (با تأیید مدیر)
            'can_suggest' => ! $canEdit && collect([$husband, $wife])->filter()->contains(fn (Person $p) => $this->edits->suggestDegree($user, $p) !== null),
            'pending_suggestion' => EditRequest::query()->where('marriage_id', $marriage->id)->where('requested_by', $user->id)->where('status', EditRequest::STATUS_PENDING)->exists(),
        ]]);
    }

    public function update(Request $request, Marriage $marriage): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Marriage::STATUSES)],
            'marriage_date' => ['nullable', new PartialDateRule],
            'end_date' => ['nullable', new PartialDateRule],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        foreach (['marriage_date', 'end_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = PartialDate::normalize($data[$field]);
            }
        }
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        if (! $this->access->canEditMarriage($request->user(), $marriage)) {
            // بستگان درجه دو و سه: پیشنهاد برای تأیید مدیر
            $suggestion = $this->edits->suggestMarriage($request->user(), $marriage, $data, $reason);

            return response()->json([
                'message' => 'پیشنهاد شما برای تأیید مدیر سایت فرستاده شد؛ پس از تأیید در درخت نمایش داده می‌شود.',
                'pending' => true,
                'request_id' => $suggestion->id,
            ], 202);
        }

        $marriage->fill($data);
        $changes = $this->audit->diff($marriage);
        $marriage->save();
        $this->audit->log('marriage.updated', $marriage, ['changes' => $changes], $request->user());

        return response()->json(['data' => NodePresenter::marriage($marriage)]);
    }

    public function destroy(Request $request, Marriage $marriage): JsonResponse
    {
        abort_unless($this->access->canEditMarriage($request->user(), $marriage), 403, 'شما اجازه حذف این ازدواج را ندارید.');
        $marriage->delete();
        $this->audit->log('marriage.deleted', $marriage, [
            'husband' => $marriage->husband_id,
            'wife' => $marriage->wife_id,
        ], $request->user());

        return response()->json(['message' => 'ازدواج حذف شد.']);
    }
}
