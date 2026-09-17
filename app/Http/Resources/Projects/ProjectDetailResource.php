<?php

namespace App\Http\Resources\Projects;

use App\Domain\Projects\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status,
            'address' => $this->address,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'budget_baseline' => $user->can('viewBudget', $this->resource) ? $this->budget_baseline : null,
            'budget_baseline_visible' => $user->can('viewBudget', $this->resource),
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client === null ? null : [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'manager_user_id' => $this->manager_user_id,
            'manager' => $this->whenLoaded('manager', fn () => [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
            ]),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'update' => $user->can('update', $this->resource),
                'delete' => $user->can('delete', $this->resource),
                'change_status' => $user->can('changeStatus', $this->resource),
                'manage_memberships' => $user->can('manageMemberships', $this->resource),
                'manage_wbs' => $user->can('manageWbs', $this->resource),
                'manage_documents' => $user->can('manageDocuments', $this->resource),
                'view_budget' => $user->can('viewBudget', $this->resource),
            ],
        ];
    }
}
