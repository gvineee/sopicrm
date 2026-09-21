<?php

namespace App\Http\Resources\Payroll;

use App\Domain\Payroll\Models\PayRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayRun */
class PayRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pay_period_id' => $this->pay_period_id,
            'pay_period' => $this->whenLoaded('payPeriod', fn () => new PayPeriodResource($this->payPeriod)),
            'status' => $this->status,
            'version' => $this->version,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'lines' => PayRunLineResource::collection($this->whenLoaded('lines')),
            'lines_sum_net_amount' => $this->lines_sum_net_amount ?? null,
            'total_net_amount' => $this->whenLoaded('lines', fn () => (string) $this->lines->sum('net_amount')),
        ];
    }
}
