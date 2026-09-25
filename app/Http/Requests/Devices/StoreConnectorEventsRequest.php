<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectorEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.native_event_id' => ['required', 'integer', 'min:0'],
            'events.*.stream_epoch' => ['required', 'integer', 'min:0'],
            'events.*.raw_device_time' => ['required', 'date'],
            // The upstream system's own UTC for this event, when it has one
            // the device's clock does not. BioStar reports both: `datetime`
            // is what the reader believed, `server_datetime` is when the
            // server recorded it — and on the live install the reader's clock
            // is three hours out while the server's matches real UTC exactly.
            // Without this, every worked hour computed from those events would
            // have been wrong by that much.
            'events.*.server_time' => ['nullable', 'date'],
            // The upstream system's own id for the PERSON who swiped. A card
            // can be replaced, lost or reissued; this identifier survives
            // that, so it is what a durable BioStar-person -> CRM-employee
            // link is anchored on.
            'events.*.external_user_ref' => ['nullable', 'string', 'max:128'],
            'events.*.event_code' => ['required', 'string', 'max:100'],
            'events.*.event_subcode' => ['nullable', 'string', 'max:100'],
            'events.*.card_type' => ['nullable', 'required_with:events.*.card_hex', 'string', 'max:100'],
            'events.*.card_hex' => ['nullable', 'required_with:events.*.card_type', 'string', 'max:256', 'regex:/^(?:0x)?[0-9A-Fa-f\s:_-]+$/'],
            'events.*.bit_length' => ['nullable', 'integer', 'min:1', 'max:2048'],
            'events.*.payload' => ['sometimes', 'array'],
            'events.*.ingestion_source' => ['sometimes', 'string', Rule::in(['device-connector', 'simulator', 'biostar-import'])],
            'events.*.clock_offset_seconds' => ['nullable', 'integer', 'between:-86400,86400'],
        ];
    }

    /**
     * @return list<array{
     *     native_event_id: int,
     *     stream_epoch: int,
     *     raw_device_time: string,
     *     server_time?: string|null,
     *     external_user_ref?: string|null,
     *     event_code: string,
     *     event_subcode?: string|null,
     *     card_type?: string|null,
     *     card_hex?: string|null,
     *     bit_length?: int|null,
     *     payload?: array<string, mixed>,
     *     ingestion_source?: string,
     *     clock_offset_seconds?: int|null
     * }>
     */
    public function events(): array
    {
        return array_values($this->validated('events'));
    }
}
