<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Marriage;
use App\Models\Person;
use App\Rules\PartialDateRule;
use App\Services\Access\PersonAccess;
use App\Services\AuditLogger;
use App\Services\Tree\NodePresenter;
use App\Support\PartialDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ویرایش و حذف ازدواج‌ها
 */
class MarriageController extends Controller
{
    public function __construct(private readonly PersonAccess $access, private readonly AuditLogger $audit) {}

    public function update(Request $request, Marriage $marriage): JsonResponse
    {
        $this->authorizeMarriage($request, $marriage);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Marriage::STATUSES)],
            'marriage_date' => ['nullable', new PartialDateRule],
            'end_date' => ['nullable', new PartialDateRule],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        foreach (['marriage_date', 'end_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = PartialDate::normalize($data[$field]);
            }
        }

        $marriage->fill($data);
        $changes = $this->audit->diff($marriage);
        $marriage->save();
        $this->audit->log('marriage.updated', $marriage, ['changes' => $changes], $request->user());

        return response()->json(['data' => NodePresenter::marriage($marriage)]);
    }

    public function destroy(Request $request, Marriage $marriage): JsonResponse
    {
        $this->authorizeMarriage($request, $marriage);
        $marriage->delete();
        $this->audit->log('marriage.deleted', $marriage, [
            'husband' => $marriage->husband_id,
            'wife' => $marriage->wife_id,
        ], $request->user());

        return response()->json(['message' => 'ازدواج حذف شد.']);
    }

    private function authorizeMarriage(Request $request, Marriage $marriage): void
    {
        $user = $request->user();
        $allowed = collect([$marriage->husband_id, $marriage->wife_id])
            ->map(fn ($id) => Person::find($id))
            ->filter()
            ->contains(fn (Person $p) => $this->access->canEdit($user, $p));

        abort_unless($allowed, 403, 'شما اجازه ویرایش این ازدواج را ندارید.');
    }
}
