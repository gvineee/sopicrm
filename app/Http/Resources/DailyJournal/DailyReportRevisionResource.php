<?php

namespace App\Http\Resources\DailyJournal;

use App\Domain\DailyJournal\Models\DailyReportRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DailyReportRevision
 */
class DailyReportRevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'snapshot' => $this->snapshot,
            'revised_by_name' => $this->whenLoaded('revisedBy', fn () => $this->revisedBy?->name),
            'revised_at' => $this->revised_at?->toIso8601String(),
            'reason' => $this->reason,
        ];
    }
}
