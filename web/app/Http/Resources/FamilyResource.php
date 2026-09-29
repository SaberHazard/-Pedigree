<?php

namespace App\Http\Resources;

use App\Models\Family;
use App\Services\Tree\NodePresenter;
use App\Support\Author;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Family
 */
class FamilyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Family $family */
        $family = $this->resource;
        $user = $request->user();

        return [
            'id' => $family->id,
            'name' => $family->name,
            'description' => $family->description,
            'color' => $family->color,
            'root' => $family->root ? NodePresenter::person($family->root) : null,
            'created_by' => $family->creator?->displayName(),
            'creator' => Author::of($family->creator),
            'created_at' => $family->created_at?->toIso8601String(),
            'can_edit' => $user && ($user->isAdmin() || $family->created_by === $user->id),
        ];
    }
}
