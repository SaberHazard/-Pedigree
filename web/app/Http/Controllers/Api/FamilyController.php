<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FamilyResource;
use App\Models\Family;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * خاندان‌ها (نقطه‌های شروع درخت در صفحه اصلی)
 */
class FamilyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return FamilyResource::collection(Family::with(['root.avatar', 'creator.person'])->orderBy('name')->get());
    }

    public function store(Request $request): FamilyResource
    {
        $family = new Family($this->validated($request));
        $family->created_by = $request->user()->id;
        $family->save();
        $this->audit->log('family.created', $family, ['name' => $family->name], $request->user());

        return new FamilyResource($family->load('root.avatar'));
    }

    public function update(Request $request, Family $family): FamilyResource
    {
        $this->authorizeFamily($request, $family);
        $family->fill($this->validated($request))->save();
        $this->audit->log('family.updated', $family, ['name' => $family->name], $request->user());

        return new FamilyResource($family->load('root.avatar'));
    }

    public function destroy(Request $request, Family $family): JsonResponse
    {
        $this->authorizeFamily($request, $family);
        $family->delete();
        $this->audit->log('family.deleted', $family, ['name' => $family->name], $request->user());

        return response()->json(['message' => 'خاندان حذف شد (اشخاص درخت باقی می‌مانند).']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'root_person_id' => ['required', 'uuid', 'exists:persons,id'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [], ['name' => 'نام خاندان', 'root_person_id' => 'جد اعلا']);
    }

    private function authorizeFamily(Request $request, Family $family): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $family->created_by === $user->id, 403, 'فقط سازنده یا مدیر می‌تواند این خاندان را ویرایش کند.');
    }
}
