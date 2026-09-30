<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Calendar\CalendarService;
use App\Services\Reminders\ReminderService;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تقویم فارسی، تبدیل تاریخ و ساعت دقیق تهران.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar) {}

    public function month(Request $request, ReminderService $reminders): JsonResponse
    {
        [$ty, $tm] = Jalali::today();
        $data = $request->validate([
            'y' => ['nullable', 'integer', 'min:'.CalendarService::MIN_YEAR, 'max:'.CalendarService::MAX_YEAR],
            'm' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        $user = $request->user();

        return response()->json(['data' => $this->calendar->month(
            $user,
            (int) ($data['y'] ?? $ty),
            (int) ($data['m'] ?? $tm),
            fn (string $from, string $to) => $reminders->byJalaliDay($user, $from, $to),
        )]);
    }

    public function convert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cal' => ['required', 'in:jalali,gregorian,hijri'],
            'y' => ['required', 'integer', 'min:1', 'max:3800'],
            'm' => ['required', 'integer', 'min:1', 'max:12'],
            'd' => ['required', 'integer', 'min:1', 'max:31'],
        ]);

        return response()->json(['data' => $this->calendar->convert($data['cal'], (int) $data['y'], (int) $data['m'], (int) $data['d'])]);
    }

    /** ساعت دقیق سرور (برای همگام کردن ساعت مرورگر و زنگ دقیق هشدارها) */
    public function time(): JsonResponse
    {
        $now = now();

        return response()->json(['data' => [
            'epoch_ms' => (int) floor($now->getPreciseTimestamp(3)),
            'tehran' => $now->copy()->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s'),
            'jalali' => Jalali::today(),
        ]], 200, ['Cache-Control' => 'no-store']);
    }
}
