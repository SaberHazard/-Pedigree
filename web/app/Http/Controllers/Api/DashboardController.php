<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\ActivityLog;
use App\Models\Family;
use App\Models\Marriage;
use App\Models\Media;
use App\Models\Person;
use App\Services\Tree\NodePresenter;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * داشبورد: آمار، مناسبت‌های پیش رو (تولد و سالگرد) و فعالیت‌های اخیر
 */
class DashboardController extends Controller
{
    /** اقداماتی که برای همه اعضا در «فعالیت‌های اخیر» نمایش داده می‌شود */
    private const PUBLIC_ACTIONS = [
        'person.created', 'relation.child_added', 'relation.spouse_added', 'relation.parent_added',
        'relation.sibling_added', 'media.approved', 'family.created', 'link.spouse', 'link.father', 'link.mother', 'link.child',
    ];

    public function stats(): JsonResponse
    {
        return response()->json([
            'persons' => Person::count(),
            'living' => Person::where('is_deceased', false)->count(),
            'deceased' => Person::where('is_deceased', true)->count(),
            'men' => Person::where('gender', Person::MALE)->count(),
            'women' => Person::where('gender', Person::FEMALE)->count(),
            'marriages' => Marriage::count(),
            'photos' => Media::where('status', Media::STATUS_APPROVED)->where('type', Media::TYPE_IMAGE)->count(),
            'videos' => Media::where('status', Media::STATUS_APPROVED)->where('type', Media::TYPE_VIDEO)->count(),
            'families' => Family::count(),
        ]);
    }

    /**
     * مناسبت‌های ۳۰ روز آینده: تولد افراد زنده و سالگرد درگذشتگان
     * (فقط برای کسانی که تاریخ کامل شمسی دارند)
     */
    public function events(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->input('days', 30)));
        [$ty, $tm, $td] = Jalali::today();
        $todayDoy = $this->dayOfYear($tm, $td);
        $yearLength = Jalali::isLeap($ty) ? 366 : 365;

        $events = [];
        Person::query()->with('avatar')
            ->where(fn ($q) => $q->where('birth_date', 'like', '____-__-__')->orWhere('death_date', 'like', '____-__-__'))
            ->chunk(500, function ($persons) use (&$events, $todayDoy, $yearLength, $days, $ty) {
                foreach ($persons as $p) {
                    $candidates = [];
                    if (! $p->is_deceased && $p->birth_date && strlen($p->birth_date) === 10) {
                        $candidates[] = ['birthday', $p->birth_date];
                    }
                    if ($p->is_deceased && $p->death_date && strlen($p->death_date) === 10) {
                        $candidates[] = ['memorial', $p->death_date];
                    }
                    foreach ($candidates as [$kind, $date]) {
                        [$y, $m, $d] = array_map('intval', explode('-', $date));
                        $diff = ($this->dayOfYear($m, $d) - $todayDoy + $yearLength) % $yearLength;
                        if ($diff <= $days) {
                            $events[] = [
                                'kind' => $kind,
                                'in_days' => $diff,
                                'date' => $date,
                                'years' => $ty - $y + ($diff > 0 && $this->dayOfYear($m, $d) < $todayDoy ? 1 : 0),
                                'person' => NodePresenter::person($p),
                            ];
                        }
                    }
                }
            });

        usort($events, fn ($a, $b) => $a['in_days'] <=> $b['in_days']);

        return response()->json(['data' => array_slice($events, 0, 50)]);
    }

    public function activity(Request $request): JsonResponse
    {
        // فعالیت‌های عمومی (لاگ کامل برای مدیران در پنل مدیریت است)
        $logs = ActivityLog::with('user.person')
            ->whereIn('action', self::PUBLIC_ACTIONS)
            ->latest('id')
            ->limit(25)
            ->get();

        // برای رسانه‌ها، شخصِ صاحب عکس نمایش داده می‌شود
        $mediaPerson = Media::withTrashed()
            ->whereIn('id', $logs->where('subject_type', 'Media')->pluck('subject_id')->unique())
            ->pluck('person_id', 'id');
        $personOf = fn (ActivityLog $log) => $log->subject_type === 'Media' ? $mediaPerson->get($log->subject_id) : ($log->subject_type === 'Person' ? $log->subject_id : null);

        // نام اشخاص مرتبط برای نمایش
        $personIds = $logs->map($personOf)->filter()->unique();
        $names = Person::withTrashed()->whereIn('id', $personIds)->get(['id', 'first_name', 'last_name', 'title'])->keyBy('id');

        return response()->json([
            'data' => $logs->map(function (ActivityLog $log) use ($request, $personOf, $names) {
                $personId = $personOf($log);

                return array_merge((new ActivityResource($log))->toArray($request), [
                    'subject_id' => $personId ?? $log->subject_id,
                    'subject_name' => $personId ? $names->get($personId)?->fullName() : null,
                ]);
            }),
        ]);
    }

    private function dayOfYear(int $month, int $day): int
    {
        return ($month <= 6 ? ($month - 1) * 31 : 186 + ($month - 7) * 30) + $day;
    }
}
