<?php

namespace App\Http\Resources\Projects;

use App\Domain\Projects\Models\ProjectLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectLocation
 */
class ProjectLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_location_id' => $this->parent_location_id,
            'level_type' => $this->level_type,
            'name' => $this->name,
            'version' => $this->version,
        ];
    }
}
