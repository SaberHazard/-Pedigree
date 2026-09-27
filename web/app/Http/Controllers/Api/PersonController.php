<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PersonRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\PersonResource;
use App\Models\ActivityLog;
use App\Models\Marriage;
use App\Models\Person;
use App\Services\People\PersonService;
use App\Services\Tree\NodePresenter;
use App\Services\Tree\RelationshipCalculator;
use App\Support\PersianText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * مدیریت اشخاص: جستجو، نمایش، ساخت، ویرایش، حذف، بستگان، تاریخچه و نسبت
 */
class PersonController extends Controller
{
    public function __construct(private readonly PersonService $persons) {}

    /**
     * جستجوی اشخاص (برای نوار جستجو و انتخاب شخص موجود)
     * پارامترها: q, gender (m|f), deceased (0|1), per_page
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'in:m,f'],
            'deceased' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Person::query()->with(['avatar', 'father:id,first_name,last_name']);

        if ($q = trim((string) $request->input('q'))) {
            foreach (array_slice(explode(' ', PersianText::searchable($q)), 0, 5) as $word) {
                if ($word !== '') {
                    $query->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
                }
            }
        }
        if ($request->filled('gender')) {
            $query->where('gender', $request->input('gender'));
        }
        if ($request->has('deceased')) {
            $query->where('is_deceased', $request->boolean('deceased'));
        }

        $page = $query->orderBy('last_name')->orderBy('first_name')->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Person $p) => NodePresenter::person($p) + [
                'father_name' => $p->father?->first_name,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Person $person): PersonResource
    {
        Gate::authorize('view', $person);

        return new PersonResource($person->load(['avatar', 'user', 'creator.person']));
    }

    /** ساخت شخص مستقل (مثلاً جد اعلای یک خاندان جدید) */
    public function store(PersonRequest $request): JsonResponse
    {
        Gate::authorize('create', Person::class);
        $person = $this->persons->create($request->validated(), $request->user());

        return (new PersonResource($person->load(['avatar', 'user'])))->response()->setStatusCode(201);
    }

    public function update(PersonRequest $request, Person $person): PersonResource
    {
        Gate::authorize('update', $person);
        $person = $this->persons->update($person, $request->validated(), $request->user());

        return new PersonResource($person->load(['avatar', 'user', 'creator.person']));
    }

    public function destroy(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('delete', $person);
        $this->persons->delete($person, $request->user());

        return response()->json(['message' => 'شخص حذف شد.']);
    }

    /** بستگان درجه یک: پدر، مادر، همسران، فرزندان، خواهر و برادرها */
    public function relatives(Person $person): JsonResponse
    {
        Gate::authorize('view', $person);

        $parents = Person::with('avatar')->whereIn('id', array_filter([$person->father_id, $person->mother_id]))->get()->keyBy('id');
        $marriages = $person->marriages()->get();
        $spouses = Person::with('avatar')->whereIn('id', $marriages->map(fn (Marriage $m) => $m->partnerOf($person->id)))->get()->keyBy('id');
        $children = $person->childrenQuery()->with('avatar')->get();
        $siblings = $person->siblingsQuery()->with('avatar')->get();

        return response()->json([
            'father' => ($f = $parents->get((string) $person->father_id)) ? NodePresenter::person($f) : null,
            'mother' => ($m = $parents->get((string) $person->mother_id)) ? NodePresenter::person($m) : null,
            'spouses' => $marriages->map(fn (Marriage $mar) => [
                'person' => ($s = $spouses->get($mar->partnerOf($person->id))) ? NodePresenter::person($s) : null,
                'marriage' => NodePresenter::marriage($mar),
            ])->filter(fn ($row) => $row['person'])->values(),
            'children' => $children->map(fn (Person $c) => NodePresenter::person($c)),
            'siblings' => $siblings->map(fn (Person $s) => NodePresenter::person($s) + [
                'half' => ! ($s->father_id === $person->father_id && $s->mother_id === $person->mother_id),
            ]),
        ]);
    }

    /** تاریخچه تغییرات یک شخص */
    public function history(Request $request, Person $person): AnonymousResourceCollection
    {
        Gate::authorize('viewHistory', $person);

        $logs = ActivityLog::query()
            ->with('user.person')
            ->where(fn (Builder $q) => $q->where('subject_type', 'Person')->where('subject_id', $person->id))
            ->orWhere(fn (Builder $q) => $q->where('subject_type', 'Media')->whereIn('subject_id', $person->media()->withTrashed()->select('id')))
            ->latest('id')
            ->paginate(30);

        return ActivityResource::collection($logs);
    }

    /** نسبت خانوادگی دو نفر */
    public function relationship(Person $person, Person $other, RelationshipCalculator $calculator): JsonResponse
    {
        Gate::authorize('view', $person);

        $result = $calculator->calculate($person, $other);
        if ($result['found'] ?? false) {
            $names = Person::whereIn('id', $result['path'])->get(['id', 'first_name', 'last_name', 'title'])->keyBy('id');
            $result['path_names'] = array_map(fn ($id) => $names->get($id)?->fullName(), $result['path']);
        }

        return response()->json($result);
    }
}
