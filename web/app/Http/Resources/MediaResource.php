<?php

namespace App\Http\Resources;

use App\Models\Media;
use App\Models\MediaVote;
use App\Services\Access\PersonAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Media $media */
        $media = $this->resource;
        $user = $request->user();
        $filesAvailable = $media->status !== Media::STATUS_REJECTED;

        $votes = $media->relationLoaded('votes') ? $media->votes : $media->votes()->get();
        $myVote = $user ? $votes->firstWhere('user_id', $user->id) : null;
        $canEditPerson = $user && $media->person && app(PersonAccess::class)->canEdit($user, $media->person);

        return [
            'id' => $media->id,
            'person_id' => $media->person_id,
            'person_name' => $media->person?->fullName(),
            'type' => $media->type,
            'status' => $media->status,
            'approval_mode' => $media->approval_mode,
            'processing' => $media->processing,
            'mime' => $media->mime,
            'size' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'duration' => $media->duration,
            'caption' => $media->caption,
            'description' => $media->description,
            'taken_at' => $media->taken_at,
            'set_as_avatar' => $media->set_as_avatar,
            'is_avatar' => $media->person?->avatar_media_id === $media->id,
            'urls' => $filesAvailable ? [
                'thumb' => $media->url('thumb'),
                'medium' => $media->isImage() ? $media->url('medium') : null,
                'poster' => $media->isVideo() ? ($media->pathFor('poster') ? $media->url('poster') : null) : null,
                'original' => $media->url('original'),
            ] : null,
            'uploader' => $media->uploader ? ['id' => $media->uploader->id, 'name' => $media->uploader->displayName()] : null,
            'votes' => [
                'total' => $votes->count(),
                'approve' => $votes->where('decision', MediaVote::APPROVE)->count(),
                'reject' => $votes->where('decision', MediaVote::REJECT)->count(),
                'mine' => $myVote?->decision,
                'is_voter' => $myVote !== null,
                'details' => $media->isPending() ? null : $votes->filter(fn ($v) => $v->decision)->map(fn ($v) => [
                    'name' => $v->user?->displayName(),
                    'decision' => $v->decision,
                    'comment' => $v->comment,
                ])->values(),
            ],
            'decided_at' => $media->decided_at?->toIso8601String(),
            'decision_note' => $media->decision_note,
            'created_at' => $media->created_at?->toIso8601String(),
            'can' => [
                'edit' => $user && ($user->id === $media->uploaded_by || $canEditPerson),
                'delete' => $user && ($user->isAdmin() || $canEditPerson || ($media->isPending() && $user->id === $media->uploaded_by)),
                'vote' => $media->isPending() && $myVote !== null && $myVote->decision === null,
                'decide' => $media->isPending() && (bool) $user?->isAdmin(),
                'set_avatar' => $media->isApproved() && $media->isImage() && $canEditPerson,
            ],
        ];
    }
}
