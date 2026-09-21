<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TEST MODE only control (App\Http\Controllers\Devices\
 * DeviceSimulatorController) — lets an operator flip a simulated device's
 * reachability without a real network. Refused entirely when the bound
 * adapter isn't the simulator (checked in the controller).
 */
class SetDeviceSimulatorStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(['online', 'offline', 'degraded', 'unknown'])],
        ];
    }
}
