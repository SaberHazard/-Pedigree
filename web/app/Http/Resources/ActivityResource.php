<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ActivityLog $log */
        $log = $this->resource;
        $isAdmin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $log->id,
            'action' => $log->action,
            'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->displayName(), 'person_id' => $log->user->person_id] : null,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'properties' => $log->properties,
            'ip_address' => $isAdmin ? $log->ip_address : null,
            'user_agent' => $isAdmin ? $log->user_agent : null,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
