<?php

namespace App\Http\Resources;

use App\Models\LinkRequest;
use App\Models\MediaVote;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\People\LinkService;
use App\Services\Tree\NodePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * اطلاعات کاربر واردشده + شمارنده‌ها برای نشان‌ها (badge) در رابط کاربری
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $person = $user->person?->loadMissing('avatar');

        $pendingVotes = MediaVote::query()
            ->where('user_id', $user->id)
            ->whereNull('decision')
            ->whereHas('media', fn ($q) => $q->where('status', 'pending'))
            ->count();

        // درخواست‌های اتصالی که این کاربر می‌تواند تأیید کند
        $links = app(LinkService::class);
        $pendingLinks = LinkRequest::with('target')
            ->where('status', 'pending')
            ->where('requested_by', '!=', $user->id)
            ->latest()->limit(200)->get()
            ->filter(fn (LinkRequest $r) => $r->target && $links->canDecide($user, $r))
            ->count();

        return [
            'id' => $user->id,
            'role' => $user->role,
            'status' => $user->status,
            'is_admin' => $user->isAdmin(),
            'preferences' => $user->preferences ?? (object) [],
            'has_password' => $user->password !== null,
            'username' => $user->username,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'person' => $person ? NodePresenter::person($person) : null,
            'counters' => [
                'notifications' => $user->unreadNotifications()->count(),
                'votes' => $pendingVotes,
                'links' => $pendingLinks,
                'messages' => app(MessagingService::class)->unreadCount($user),
            ],
        ];
    }
}
