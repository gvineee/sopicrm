<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'internal_code' => strtoupper(fake()->unique()->bothify('EMP-####')),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->phoneNumber(),
            'position' => fake()->jobTitle(),
            'status' => 'active',
        ];
    }
}
