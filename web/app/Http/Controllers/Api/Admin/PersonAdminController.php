<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Services\People\MergeService;
use App\Services\People\PersonService;
use App\Services\Tree\NodePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ابزارهای مدیریتی اشخاص: سطل زباله، بازیابی و ادغام پروفایل‌های تکراری
 */
class PersonAdminController extends Controller
{
    public function trash(): JsonResponse
    {
        $persons = Person::onlyTrashed()->latest('deleted_at')->limit(200)->get();

        return response()->json([
            'data' => $persons->map(fn (Person $p) => NodePresenter::person($p) + [
                'deleted_at' => $p->deleted_at?->toIso8601String(),
            ]),
        ]);
    }

    public function restore(Request $request, string $id, PersonService $persons): JsonResponse
    {
        $person = Person::onlyTrashed()->findOrFail($id);
        $persons->restore($person, $request->user());

        return response()->json(['message' => 'شخص بازیابی شد.']);
    }

    public function merge(Request $request, Person $person, MergeService $merge): PersonResource
    {
        $data = $request->validate(['duplicate_id' => ['required', 'uuid', 'exists:persons,id']]);
        $duplicate = Person::findOrFail($data['duplicate_id']);

        return new PersonResource($merge->merge($person, $duplicate, $request->user())->load(['avatar', 'user']));
    }
}
