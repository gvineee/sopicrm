<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceSyncCommand>
 */
class DeviceSyncCommandFactory extends Factory
{
    protected $model = DeviceSyncCommand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'device_id' => Device::factory(),
            'command_type' => 'add_user',
            'payload' => ['example' => true],
            'idempotency_key' => (string) Str::uuid(),
            'command_version' => 1,
            'status' => 'pending',
            'attempts' => 0,
        ];
    }
}
