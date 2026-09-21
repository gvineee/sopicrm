<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExternalIdentifierMapping>
 */
class ExternalIdentifierMappingFactory extends Factory
{
    protected $model = ExternalIdentifierMapping::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'source_system' => 'biostar',
            'source_instance_key' => 'default',
            'external_type' => 'card',
            'external_identifier' => 'EM:'.Str::upper(Str::random(8)),
            'status' => 'pending',
            'first_seen_at' => now(),
        ];
    }
}
