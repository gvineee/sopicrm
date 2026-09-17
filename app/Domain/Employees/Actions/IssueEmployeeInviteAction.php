<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\EmployeeInvite;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * spec section 5: "HR-მა უნდა შეძლოს მოწვევის შექმნა; ერთჯერადი ბმული ვადიანი
 * იყოს. საერთო თანამშრომლის ანგარიშები არ გამოიყენო." (HR can create an
 * invite; the one-time link is time-limited; shared employee accounts are
 * never used.) Returns the raw, unhashed token — it exists ONLY in this
 * return value and the resulting one-time URL; only its SHA-256 hash is
 * persisted (App\Domain\Employees\Models\EmployeeInvite).
 */
class IssueEmployeeInviteAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return array{invite: EmployeeInvite, token: string}
     */
    public function execute(Employee $employee, User $issuer): array
    {
        if ($employee->user_id !== null) {
            throw new RuntimeException('თანამშრომელს უკვე აქვს დაკავშირებული ანგარიში — ახალი მოწვევა საჭირო არ არის.');
        }

        if ($employee->status === 'terminated') {
            throw new RuntimeException('დათხოვნილ თანამშრომელზე მოწვევის გაგზავნა შეუძლებელია.');
        }

        return DB::transaction(function () use ($employee, $issuer) {
            // Revoke any still-pending invite first — the DB's partial
            // unique index (employee_invites_pending_unique) only allows one
            // PENDING row per employee at a time, so "resend" must clear the
            // old one rather than error.
            EmployeeInvite::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => $issuer->id]);

            $token = Str::random(64);

            $invite = EmployeeInvite::query()->create([
                'employee_id' => $employee->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays((int) config('employees.invite_ttl_days', 7)),
                'status' => 'pending',
                'created_by_user_id' => $issuer->id,
            ]);

            $this->auditLogger->log(
                action: 'employees.invite.issued',
                target: $invite,
                after: ['employee_id' => $employee->id, 'expires_at' => $invite->expires_at->toIso8601String()],
                actor: $issuer,
            );

            return ['invite' => $invite, 'token' => $token];
        });
    }
}
