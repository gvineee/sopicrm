<?php

namespace App\Http\Resources\Projects;

use App\Domain\Auth\Models\ProjectMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectMembership
 */
class ProjectMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user->name),
            'user_email' => $this->whenLoaded('user', fn () => $this->user->email),
            'role_context' => $this->role_context,
            'added_at' => $this->created_at->toIso8601String(),
            'removed_at' => $this->removed_at?->toIso8601String(),
        ];
    }
}
