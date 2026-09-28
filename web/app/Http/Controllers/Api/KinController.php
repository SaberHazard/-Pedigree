<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\User;
use App\Services\KinshipDegrees;
use App\Services\Tree\NodePresenter;
use App\Services\Tree\RelationshipCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «بستگان درجه ۱ تا ۴ من چه کسانی هستند؟»
 *
 * برای اینکه وقتی کسی می‌گوید «شماره‌ام را فقط بستگان تا درجه ۳ ببینند»
 * دقیقاً بداند چه کسانی (با نام و نسبت) آن را می‌بینند.
 */
class KinController extends Controller
{
    public function index(Request $request, Person $person, KinshipDegrees $degrees, RelationshipCalculator $relations): JsonResponse
    {
        Gate::authorize('view', $person);
        $max = max(1, min((int) $request->integer('max', KinshipDegrees::MAX), KinshipDegrees::MAX));

        $kin = $degrees->from($person, $max);
        $people = collect();
        foreach (array_chunk(array_keys($kin), 500) as $chunk) {
            $people = $people->merge(Person::query()->with('avatar')->whereIn('id', $chunk)->get());
        }
        $people = $people->keyBy('id');
        $accounts = collect();
        foreach (array_chunk(array_keys($kin), 500) as $chunk) {
            $accounts = $accounts->merge(User::query()->whereIn('person_id', $chunk)->where('status', User::STATUS_ACTIVE)->pluck('person_id'));
        }
        $accounts = $accounts->flip();

        $paths = [];
        foreach ($kin as $row) {
            array_push($paths, ...$row['path']);
        }
        $relations->preload($paths);

        $groups = [];
        foreach ($kin as $id => $row) {
            $p = $people->get($id);
            if ($p === null || $row['degree'] > $max) {
                continue;
            }
            $groups[$row['degree']][] = [
                'person' => NodePresenter::person($p),
                'label' => $relations->labelForPath($row['path']),
                'inlaw' => $row['inlaw'],
                'has_account' => $accounts->has($id),
            ];
        }
        ksort($groups);

        $data = [];
        foreach ($groups as $degree => $rows) {
            usort($rows, fn ($a, $b) => [$a['inlaw'], $a['label'], $a['person']['first_name'] ?? ''] <=> [$b['inlaw'], $b['label'], $b['person']['first_name'] ?? '']);
            $data[] = ['degree' => $degree, 'count' => count($rows), 'people' => $rows];
        }

        return response()->json([
            'data' => $data,
            'max' => $max,
            'total' => array_sum(array_column($data, 'count')),
        ]);
    }
}
