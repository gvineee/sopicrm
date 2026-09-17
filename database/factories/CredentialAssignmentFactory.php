<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CredentialAssignment>
 */
class CredentialAssignmentFactory extends Factory
{
    protected $model = CredentialAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'credential_id' => Credential::factory(),
            'employee_id' => Employee::factory(),
            'valid_from' => now(),
            'status' => 'active',
        ];
    }
}
