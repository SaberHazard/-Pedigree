<?php

namespace App\Http\Resources;

use App\Models\LinkRequest;
use App\Services\People\LinkService;
use App\Services\Tree\NodePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LinkRequest
 */
class LinkRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LinkRequest $link */
        $link = $this->resource;
        $user = $request->user();

        return [
            'id' => $link->id,
            'type' => $link->type,
            'status' => $link->status,
            'message' => $link->message,
            'payload' => $link->payload,
            'subject' => $link->subject ? NodePresenter::person($link->subject) : null,
            'target' => $link->target ? NodePresenter::person($link->target) : null,
            'requester' => $link->requester?->displayName(),
            'created_at' => $link->created_at?->toIso8601String(),
            'decided_at' => $link->decided_at?->toIso8601String(),
            'can' => [
                'decide' => $user && app(LinkService::class)->canDecide($user, $link),
                'cancel' => $user && $link->status === 'pending' && ($link->requested_by === $user->id || $user->isAdmin()),
            ],
        ];
    }
}
