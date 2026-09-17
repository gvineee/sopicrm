<?php

namespace App\Http\Resources\Projects;

use App\Domain\Projects\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
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
            // Financial figure: masked server-side for anyone without
            // 'projects.budget.view' — spec section 3 hard rule ("სისტემურ
            // ადმინისტრატორს ფინანსური წვდომა ავტომატურად არ მიენიჭოს") and
            // the client/subcontractor role's "თვითღირებულება ... დამალულია".
            // Real masking, not a UI-only omission: the value never leaves
            // the server in the response payload.
            'budget_baseline' => $user->can('viewBudget', $this->resource) ? $this->budget_baseline : null,
            'budget_baseline_visible' => $user->can('viewBudget', $this->resource),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'manager' => $this->whenLoaded('manager', fn () => [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
            ]),
            'members_count' => $this->whenCounted('memberships'),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
