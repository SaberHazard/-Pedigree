<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LinkRequestResource;
use App\Models\LinkRequest;
use App\Services\People\LinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * درخواست‌های اتصال درخت‌ها
 */
class LinkRequestController extends Controller
{
    public function __construct(private readonly LinkService $links) {}

    /** درخواست‌هایی که من باید تأیید کنم + درخواست‌های خودم */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $incoming = LinkRequest::with(['subject', 'target', 'requester.person'])
            ->where('status', 'pending')
            ->where('requested_by', '!=', $user->id)
            ->latest()->limit(200)->get()
            ->filter(fn (LinkRequest $r) => $r->target && $this->links->canDecide($user, $r));

        $mine = LinkRequest::with(['subject', 'target', 'requester.person'])
            ->where('requested_by', $user->id)
            ->latest()->limit(30)->get();

        return LinkRequestResource::collection($incoming->concat($mine)->unique('id')->values());
    }

    public function accept(Request $request, LinkRequest $linkRequest): JsonResponse
    {
        $this->links->accept($linkRequest, $request->user());

        return response()->json(['message' => 'اتصال انجام شد.']);
    }

    public function reject(Request $request, LinkRequest $linkRequest): JsonResponse
    {
        $this->links->reject($linkRequest, $request->user());

        return response()->json(['message' => 'درخواست رد شد.']);
    }

    public function cancel(Request $request, LinkRequest $linkRequest): JsonResponse
    {
        $this->links->cancel($linkRequest, $request->user());

        return response()->json(['message' => 'درخواست لغو شد.']);
    }
}
