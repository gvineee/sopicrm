<?php

namespace App\Http\Resources\Projects;

use App\Domain\Shared\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attachment
 */
class ProjectDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'byte_size' => $this->byte_size,
            'status' => $this->status,
            'caption' => $this->caption,
            'classification' => $this->classification,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
