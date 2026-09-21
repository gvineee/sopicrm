<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TEST MODE only control that feeds
 * App\Domain\Devices\Adapters\SimulatorDeviceAdapter::generateEventPayload()
 * — shaped exactly like a real connector's ingestion payload, per that
 * method's own contract, so the whole ingest/anomaly pipeline is exercised
 * the same way a real event would exercise it.
 */
class GenerateSimulatorEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'native_event_id' => ['required', 'integer', 'min:1'],
            'stream_epoch' => ['required', 'integer', 'min:1'],
            'card_type' => ['nullable', 'string', 'max:50'],
            'card_hex' => ['nullable', 'string', 'max:64'],
            'direction' => ['required', Rule::in(['in', 'out', 'unspecified'])],
            'event_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
