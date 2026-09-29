<?php

namespace App\Http\Resources;

use App\Models\LinkRequest;
use App\Models\MediaVote;
use App\Models\User;
use App\Services\Group\GroupService;
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

        // عضو در انتظار تأیید: فقط اطلاعات خودش (بدون شمارنده‌ها و چیزی از بقیه شجره‌نامه)
        if ($user->isPending()) {
            return [
                'id' => $user->id,
                'role' => $user->role,
                'status' => $user->status,
                'is_admin' => false,
                'preferences' => (object) [],
                'has_password' => $user->password !== null,
                'username' => $user->username,
                'join_note' => $user->join_note,
                'person' => $person ? ['id' => $person->id, 'first_name' => $person->first_name, 'last_name' => $person->last_name, 'gender' => $person->gender] : null,
                'counters' => ['notifications' => 0, 'votes' => 0, 'links' => 0, 'messages' => 0, 'group' => 0],
            ];
        }

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
                'group' => app(GroupService::class)->unreadCount($user),
            ],
        ];
    }
}
