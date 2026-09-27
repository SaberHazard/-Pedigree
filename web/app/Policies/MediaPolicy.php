<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;
use App\Services\Access\PersonAccess;
use App\Services\Media\ApprovalService;

/**
 * سیاست دسترسی عکس و ویدیو
 */
class MediaPolicy
{
    public function __construct(
        private readonly PersonAccess $access,
        private readonly ApprovalService $approvals,
    ) {}

    public function view(?User $user, Media $media): bool
    {
        if ($media->isApproved()) {
            return $this->access->canView($user, $media->person);
        }

        return $user !== null && $this->approvals->canSeePending($user, $media);
    }

    public function update(User $user, Media $media): bool
    {
        return $user->id === $media->uploaded_by || $this->access->canEdit($user, $media->person);
    }

    public function delete(User $user, Media $media): bool
    {
        if ($user->isAdmin() || $this->access->canEdit($user, $media->person)) {
            return true;
        }

        return $media->isPending() && $user->id === $media->uploaded_by;
    }
}
