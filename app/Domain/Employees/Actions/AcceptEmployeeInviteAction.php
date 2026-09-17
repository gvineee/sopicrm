<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Exceptions\InvalidEmployeeInviteException;
use App\Domain\Employees\Models\EmployeeInvite;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Completes the HR-issued, one-time invite flow (spec section 5): creates a
 * brand-new, single-purpose login `User` account for the Employee named on
 * the invite, links it (`employees.user_id`), and marks the invite consumed
 * — a single-use link can never be replayed to create a second account or
 * re-link a different employee.
 *
 * Runs with NO authenticated user and therefore no tenant context yet
 * established by App\Http\Middleware\SetCurrentOrganization (this is a
 * public, unauthenticated route by design — the one-time token itself is
 * the credential). The invite row is looked up with the tenant scope
 * bypassed (mirroring App\Domain\Shared\Services\AuditLogger's own
 * documented pattern for the same structural reason), and
 * CurrentOrganization is then set explicitly, scoped to this one request,
 * from the invite's own organization_id — never from client input.
 */
class AcceptEmployeeInviteAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $data
     */
    public function execute(string $rawToken, array $data): User
    {
        $tokenHash = hash('sha256', $rawToken);

        /** @var EmployeeInvite|null $invite */
        $invite = EmployeeInvite::withoutTenantScope()
            ->with('employee')
            ->where('token_hash', $tokenHash)
            ->first();

        if ($invite === null || $invite->status !== 'pending' || $invite->expires_at->isPast()) {
            throw new InvalidEmployeeInviteException('მოწვევის ბმული არასწორია ან ვადაგასულია.');
        }

        $employee = $invite->employee;

        if ($employee === null || $employee->user_id !== null || $employee->status === 'terminated') {
            throw new InvalidEmployeeInviteException('მოწვევის ბმული აღარ არის აქტიური.');
        }

        $previousOrganization = CurrentOrganization::id();
        CurrentOrganization::set($invite->organization_id);

        try {
            return DB::transaction(function () use ($invite, $employee, $data) {
                // App\Models\User declares its mass-assignable fields via a
                // #[Fillable(['name','email','password','phone','is_active'])]
                // attribute that deliberately does NOT include
                // organization_id/current_organization_id (see that model's
                // own docblock — User is excluded from BelongsToOrganization
                // and its auto-stamping `creating` hook). Direct attribute
                // assignment (not mass-assignment via create()/fill()) is
                // the correct, guard-respecting way to set them here, the
                // same posture the model's own factory documents needing
                // (docs/decisions.md DEC-070).
                $user = new User;
                $user->organization_id = $invite->organization_id;
                $user->current_organization_id = $invite->organization_id;
                $user->name = $data['name'];
                $user->email = $data['email'];
                // App\Models\User casts `password` as 'hashed' (Laravel's
                // built-in hashed-attribute cast), so assigning the plain
                // value here hashes it on save — no manual Hash::make()
                // needed (and safe against double-hashing either way, since
                // that cast detects an already-hashed value).
                $user->password = $data['password'];
                $user->phone = $data['phone'] ?? $employee->phone;
                $user->is_active = true;
                $user->save();

                $user->assignRole('employee');

                $employee->user_id = $user->id;
                $employee->save();

                $invite->status = 'accepted';
                $invite->accepted_at = now();
                $invite->accepted_user_id = $user->id;
                $invite->save();

                $this->auditLogger->log(
                    action: 'employees.invite.accepted',
                    target: $invite,
                    after: ['employee_id' => $employee->id, 'user_id' => $user->id],
                    actor: $user,
                    organizationId: $invite->organization_id,
                );

                return $user;
            });
        } finally {
            CurrentOrganization::set($previousOrganization);
        }
    }
}
