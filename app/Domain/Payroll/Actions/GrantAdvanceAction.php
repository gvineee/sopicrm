<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\Advance;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class GrantAdvanceAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Employee $employee, string $amount, string $currency, string $reason, User $actor): Advance
    {
        $advance = Advance::query()->create([
            'employee_id' => $employee->id,
            'amount' => $amount,
            'currency' => $currency,
            'granted_at' => now(),
            'granted_by_user_id' => $actor->id,
            'reason' => $reason,
            'status' => 'outstanding',
        ]);

        $this->auditLogger->log(
            action: 'payroll.advance.granted',
            target: $advance,
            after: $advance->only(['employee_id', 'amount', 'reason']),
            actor: $actor,
        );

        return $advance;
    }
}
