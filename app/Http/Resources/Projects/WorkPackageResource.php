<?php

namespace App\Http\Resources\Projects;

use App\Domain\Projects\Models\WorkPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkPackage
 */
class WorkPackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_location_id' => $this->project_location_id,
            'name' => $this->name,
            'description' => $this->description,
            'version' => $this->version,
        ];
    }
}
