<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EditRequest;
use App\Services\People\EditRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت ← «تأیید ویرایش‌ها»: پیشنهادهای بستگان درجه دو و سه
 */
class EditRequestController extends Controller
{
    public function __construct(private readonly EditRequestService $edits) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'string', Rule::in(['pending', 'approved', 'rejected'])]]);
        $status = $data['status'] ?? EditRequest::STATUS_PENDING;
        $rows = EditRequest::query()->with(['person.avatar', 'marriage', 'requester.person', 'decider'])
            ->where('status', $status)
            ->when($status === EditRequest::STATUS_PENDING, fn ($q) => $q->oldest('id'), fn ($q) => $q->latest('decided_at'))
            ->limit(100)->get();

        return response()->json([
            'data' => $rows->map(fn (EditRequest $r) => $this->edits->present($r))->values(),
            'pending' => EditRequest::query()->where('status', EditRequest::STATUS_PENDING)->count(),
        ]);
    }

    public function approve(Request $request, EditRequest $editRequest): JsonResponse
    {
        $this->edits->approve($editRequest, $request->user());

        return response()->json(['message' => 'تأیید و در سایت اعمال شد.']);
    }

    public function reject(Request $request, EditRequest $editRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $this->edits->reject($editRequest, $request->user(), $data['note'] ?? null);

        return response()->json(['message' => 'پیشنهاد رد شد.']);
    }
}
