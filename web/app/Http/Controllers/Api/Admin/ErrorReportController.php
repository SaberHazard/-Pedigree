<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErrorReport;
use App\Support\ErrorReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * خطاهای ثبت‌شده سرور و مرورگر (فقط مدیر کل): هر خطای یکسان یک ردیف با شمارنده و کد پیگیری.
 */
class ErrorReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,resolved,all'],
            'source' => ['nullable', 'in:server,client'],
            'q' => ['nullable', 'string', 'max:40'],
        ]);
        $status = $data['status'] ?? 'open';
        $query = ErrorReport::query()->with('user.person:id,first_name,last_name')->orderByDesc('last_seen_at');
        if ($status === 'open') {
            $query->whereNull('resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('resolved_at');
        }
        if (! empty($data['source'])) {
            $query->where('source', $data['source']);
        }
        if (! empty($data['q'])) {
            $query->where('ref', strtoupper(trim($data['q'])));
        }

        return response()->json([
            'data' => $query->limit(300)->get()->map(fn (ErrorReport $r) => [
                'id' => $r->id,
                'ref' => $r->ref,
                'source' => $r->source,
                'type' => $r->type,
                'message' => $r->message,
                'location' => $r->location,
                'path' => $r->path,
                'method' => $r->method,
                'count' => $r->count,
                'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->displayName(), 'username' => $r->user->username, 'person_id' => $r->user->person_id] : null,
                'first_seen_at' => $r->first_seen_at?->toIso8601String(),
                'last_seen_at' => $r->last_seen_at?->toIso8601String(),
                'resolved_at' => $r->resolved_at?->toIso8601String(),
            ]),
            'meta' => [
                'open' => ErrorReport::query()->whereNull('resolved_at')->count(),
                'last_24h' => ErrorReport::query()->whereNull('resolved_at')->where('last_seen_at', '>=', now()->subDay())->count(),
            ],
        ]);
    }

    public function resolve(ErrorReport $report): JsonResponse
    {
        $report->forceFill(['resolved_at' => now()])->save();

        return response()->json(['message' => 'به عنوان «رفع شد» علامت خورد.']);
    }

    public function resolveAll(): JsonResponse
    {
        $n = ErrorReport::query()->whereNull('resolved_at')->update(['resolved_at' => now()]);

        return response()->json(['message' => "{$n} خطا رفع‌شده علامت خورد."]);
    }

    public function clearResolved(): JsonResponse
    {
        $n = ErrorReport::query()->whereNotNull('resolved_at')->delete();

        return response()->json(['message' => "{$n} خطای رفع‌شده پاک شد."]);
    }

    /** خطای مرورگر اعضا (از window.onerror)؛ ذخیره پاک‌سازی‌شده با سقف و بدون پاسخ جزئیات */
    public function client(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'type' => ['nullable', 'string', 'max:60'],
            'source' => ['nullable', 'string', 'max:300'],
            'line' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'page' => ['nullable', 'string', 'max:300'],
        ]);
        $ref = ErrorReporter::recordClient($data, $request->user()?->id);

        return response()->json(['ref' => $ref], 202);
    }
}
