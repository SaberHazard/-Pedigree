<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\Kinship;
use App\Services\Tree\NodePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * نقشه خاندان: محل زندگی اعضا (فقط کسانی که اجازه نمایش داده‌اند یا بستگان درجه یک بیننده)
 * و آرامگاه درگذشتگان.
 */
class MapController extends Controller
{
    public function index(Request $request, Kinship $kinship): JsonResponse
    {
        if (! config('pedigree.map.enabled', true)) {
            throw new DomainException('نقشه غیرفعال است.', 404);
        }
        $user = $request->user();
        $limit = (int) config('pedigree.tree.max_nodes', 5000);

        // کسانی که بیننده بدون اجازه عمومی هم می‌تواند خانه‌شان را ببیند: خودش و بستگان درجه یک
        $close = [];
        if ($user->person) {
            $viewer = $user->person;
            $close[] = $viewer->id;
            foreach (['parents', 'children', 'siblings', 'spouses'] as $group) {
                array_push($close, ...$kinship->group($viewer, $group)->pluck('id')->all());
            }
        }

        $points = [];
        $homes = Person::query()->with('avatar')
            ->whereNotNull('home_lat')
            ->where('is_deceased', false)
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('share_location', true)->orWhereIn('id', $close)))
            ->limit($limit)
            ->get();
        foreach ($homes as $person) {
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
