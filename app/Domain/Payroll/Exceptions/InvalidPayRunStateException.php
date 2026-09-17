<?php

namespace App\Domain\Payroll\Exceptions;

/**
 * spec section 8 state machine: draft -> calculated -> reviewed -> approved
 * -> locked. Every transition Action checks the PayRun's CURRENT status
 * against the one transition it implements and rejects any other current
 * status here, so an invalid jump (e.g. draft -> approved, or any action on
 * an already-locked run) can never silently succeed via a second code path.
 */
class InvalidPayRunStateException extends PayrollDomainException
{
    public function __construct(string $currentStatus, string $requiredStatus, string $action)
    {
        parent::__construct(
            "Cannot {$action} a PayRun in status [{$currentStatus}]; requires status [{$requiredStatus}].",
            'invalid_pay_run_state',
            409,
            ['status' => ["ეს მოქმედება შეუძლებელია სტატუსში [{$currentStatus}] — საჭიროა [{$requiredStatus}]."]],
        );
    }
}
