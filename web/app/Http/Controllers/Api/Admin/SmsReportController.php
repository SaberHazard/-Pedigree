<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Services\Sms\SmsManager;
use App\Services\Tree\NodePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** گزارش پیامک‌های تبریک اعضا برای مدیران */
class SmsReportController extends Controller
{
    public function index(Request $request, SmsManager $sms): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string', Rule::in([SmsMessage::STATUS_SENT, SmsMessage::STATUS_FAILED])]]);
        $query = SmsMessage::with(['sender.person', 'recipient'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id');
        $page = $query->paginate(40);
        $today = now('Asia/Tehran')->toDateString();

        return response()->json([
            'data' => collect($page->items())->map(fn (SmsMessage $m) => [
                'id' => $m->id,
                'sender' => $m->sender?->person ? NodePresenter::person($m->sender->person) : null,
                'recipient' => $m->recipient ? NodePresenter::person($m->recipient) : null,
                'phone_hint' => $m->phone_hint,
                'provider' => $m->provider,
                'status' => $m->status,
                'auto' => $m->auto,
                'body' => $m->body,
                'error' => $m->error,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'stats' => [
                'today' => SmsMessage::where('status', 'sent')->whereDate('sent_on', $today)->count(),
                'month' => SmsMessage::where('status', 'sent')->whereDate('sent_on', '>=', now('Asia/Tehran')->subDays(29)->toDateString())->count(),
                'failed_month' => SmsMessage::where('status', 'failed')->whereDate('sent_on', '>=', now('Asia/Tehran')->subDays(29)->toDateString())->count(),
                'provider' => $sms->messageDriverName(),
            ],
        ]);
    }
}
