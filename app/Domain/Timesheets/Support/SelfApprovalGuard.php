<?php

namespace App\Domain\Timesheets\Support;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\Exceptions\SelfApprovalNotAllowedException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * spec section 3: "საკუთარი ტაბელის ან საკუთარი ფინანსური მოთხოვნის
 * საბოლოო დამტკიცება ნაგულისხმევად აკრძალულია. მცირე კომპანიის
 * გამონაკლისი მხოლოდ მფლობელის ცალკე უფლებით და აუდიტით." Shared by every
 * Timesheets-module approval-type Action so the rule (and its one narrow,
 * audited exception) lives in exactly one place.
 */
final class SelfApprovalGuard
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  string  $subjectEmployeeId  The Employee whose record is being approved/decided.
     * @param  bool  $ownerExceptionAcknowledged  True only when the caller explicitly opted into the
     *                                            owner-only small-company self-approval exception.
     */
    public function assertAllowed(
        User $approver,
        string $subjectEmployeeId,
        bool $ownerExceptionAcknowledged,
        Model $auditTarget,
        ?string $exceptionReason,
    ): void {
        $approverEmployee = Employee::query()->where('user_id', $approver->id)->first();

        if ($approverEmployee === null || $approverEmployee->id !== $subjectEmployeeId) {
            return;
        }

        if (! $approver->hasRole('owner') || ! $ownerExceptionAcknowledged) {
            throw new SelfApprovalNotAllowedException;
        }

        $this->auditLogger->log(
            action: 'timesheets.self_approval_exception_used',
            target: $auditTarget,
            reason: $exceptionReason ?? 'owner small-company self-approval exception (spec section 3)',
            actor: $approver,
        );
    }
}
