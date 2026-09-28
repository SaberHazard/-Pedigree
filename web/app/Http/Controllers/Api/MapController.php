<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\KinshipDegrees;
use App\Services\Tree\NodePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * نقشه خاندان: محل زندگی اعضا (طبق تنظیم هر شخص: همه اعضا / بستگان تا درجه ... / فقط خودش)
 * و آرامگاه درگذشتگان.
 */
class MapController extends Controller
{
    public function index(Request $request, KinshipDegrees $degrees): JsonResponse
    {
        if (! config('pedigree.map.enabled', true)) {
            throw new DomainException('نقشه غیرفعال است.', 404);
        }
        $user = $request->user();
        $limit = (int) config('pedigree.tree.max_nodes', 5000);

        // بستگان بیننده تا درجه ۴ (برای سطح‌های «فقط بستگان تا درجه ...»)
        $kin = $user->person ? $degrees->from($user->person) : [];

        $points = [];
        $homes = Person::query()->with('avatar')
            ->whereNotNull('home_lat')
            ->where('is_deceased', false)
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('location_visibility', 'all')
                ->when($user->person_id, fn (Builder $q) => $q->orWhere('id', $user->person_id))
                ->when($kin, fn (Builder $q) => $q->orWhereIn('id', array_keys($kin)))))
            ->limit($limit)
            ->get();
        foreach ($homes as $person) {
            if (! $user->isAdmin() && $person->id !== $user->person_id && $person->location_visibility !== 'all') {
                $max = KinshipDegrees::levelMax($person->location_visibility ?: 'd1');
                // درجه بیننده از دید صاحب خانه (عروس/داماد برای پدرزن و مادرشوهر درجه یک است)
                $degree = $kin[$person->id]['reverse'] ?? null;
                if ($max === 0 || $degree === null || ($max !== null && $degree > $max)) {
                    continue;
                }
            }
            if ($location = $person->homeLocation()) {
                $points[] = ['kind' => 'home', 'person' => NodePresenter::person($person), 'city' => $person->city, 'country' => $person->country] + $location;
            }
        }

        $graves = Person::query()->with('avatar')
            ->whereNotNull('burial_lat')->whereNotNull('burial_lng')
            ->limit($limit)
            ->get();
        foreach ($graves as $person) {
            $points[] = [
                'kind' => 'burial',
                'person' => NodePresenter::person($person),
                'place' => $person->burial_place,
                'lat' => $person->burial_lat,
                'lng' => $person->burial_lng,
            ];
        }

        return response()->json(['data' => $points]);
    }
}
