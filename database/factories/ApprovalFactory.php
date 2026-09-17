<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 *
 * Defaults `approvable` to a plain Employee row only to keep the factory's
 * own dependency graph light — real callers pass `->for($theRealApprovable,
 * 'approvable')` for whichever entity (Timesheet, PayRun, ...) they're
 * actually testing.
 */
class ApprovalFactory extends Factory
{
    protected $model = Approval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'approvable_type' => Employee::class,
            'approvable_id' => Employee::factory(),
            'target_version' => 1,
            'approver_user_id' => User::factory(),
            'decision' => 'approved',
            'decided_at' => now(),
        ];
    }
}
