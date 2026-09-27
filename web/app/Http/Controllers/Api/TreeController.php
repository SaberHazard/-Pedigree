<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\Tree\TreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * داده‌های درخت برای رسم (وب و اپ)
 *
 *  GET /api/tree/{person}/descendants?depth=4
 *  GET /api/tree/{person}/ancestors?depth=6
 *  GET /api/tree/{person}/hourglass?up=3&down=3
 *  GET /api/tree/lineage?from={id}&to={id}&down=0
 */
class TreeController extends Controller
{
    public function __construct(private readonly TreeService $tree) {}

    public function descendants(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('view', $person);

        return response()->json($this->tree->descendants($person->load('avatar'), $this->depth($request, 'depth')));
    }

    public function ancestors(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('view', $person);

        return response()->json($this->tree->ancestors($person->load('avatar'), $this->depth($request, 'depth', 8)));
    }

    public function hourglass(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('view', $person);

        return response()->json($this->tree->hourglass(
            $person->load('avatar'),
            $this->depth($request, 'up', 4),
            $this->depth($request, 'down', 3),
        ));
    }

    public function lineage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'uuid'],
            'to' => ['required', 'uuid'],
        ]);
        $from = Person::findOrFail($data['from']);
        $to = Person::findOrFail($data['to']);
        Gate::authorize('view', $from);

        $result = $this->tree->lineage($from, $to, $this->depth($request, 'down', 0));
        if ($result === null) {
            throw new DomainException('بین این دو نفر رابطه نسبیِ مستقیم (جد و نواده) ثبت نشده است.', 404);
        }

        return response()->json($result);
    }

    private function depth(Request $request, string $key, ?int $default = null): int
    {
        $max = (int) config('pedigree.tree.max_depth', 30);
        $value = (int) $request->input($key, $default ?? config('pedigree.tree.default_depth', 4));

        return max(0, min($max, $value));
    }
}
