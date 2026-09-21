<?php

namespace App\Policies;

use App\Domain\Devices\Support\CompanyScope;
use App\Domain\Employees\Models\Employee;
use App\Models\User;

/**
 * spec section 3 role table for the Employees domain: HR and owner manage
 * the roster; a foreman may see (never edit) their own brigade's roster; an
 * employee may see their own profile only ("სხვის ჩანაწერებს ვერ ცვლის" —
 * can never modify someone else's record). Personal ID number and rate
 * visibility are separately permissioned (see viewPersonalId() /
 * app/Policies/RateHistoryPolicy.php) — spec section 5: "პირადი ნომერი
 * საჭიროების შემთხვევაში შეზღუდული წვდომით."
 *
 * Every method checks organization_id first — a cross-tenant Employee row
 * is denied regardless of role/permission, defense-in-depth alongside the
 * BelongsToOrganization global scope + Postgres RLS.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.employees.view') || $this->ownsAForeman($user);
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($employee->organization_id !== $user->organization_id) {
            return false;
        }

        // Seeing your own record, or your own brigade member's, is a
        // narrow relationship-based grant, not the broad
        // org-wide-roster-visibility permission — TENANT-01's
        // company-scoping only tightens the broad grant below, it never
        // blocks a person from seeing themself or their own team.
        if ($employee->user_id !== null && $employee->user_id === $user->id) {
            return true;
        }

        if ($this->isForemanOfEmployee($user, $employee)) {
            return true;
        }

        return $user->can('employees.employees.view')
            && CompanyScope::allows($employee->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $user->can('employees.employees.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $employee->organization_id === $user->organization_id
            && $user->can('employees.employees.manage')
            && CompanyScope::allows($employee->company_id, $user);
    }

    /**
     * spec section 5: "პირადი ნომერი საჭიროების შემთხვევაში შეზღუდული
     * წვდომით" — a separate permission from ordinary profile viewing. An
     * employee may always see their own.
     */
    public function viewPersonalId(User $user, Employee $employee): bool
    {
        if ($employee->organization_id !== $user->organization_id) {
            return false;
        }

        if ($employee->user_id !== null && $employee->user_id === $user->id) {
            return true;
        }

        return $user->can('employees.personal_id.view');
    }

    public function viewDocuments(User $user, Employee $employee): bool
    {
        if ($employee->organization_id !== $user->organization_id) {
            return false;
        }

        if ($employee->user_id !== null && $employee->user_id === $user->id) {
            return true;
        }

        return $user->can('employees.documents.view');
    }

    public function manageDocuments(User $user, Employee $employee): bool
    {
        return $employee->organization_id === $user->organization_id
            && $user->can('employees.documents.manage');
    }

    public function manageInvite(User $user, Employee $employee): bool
    {
        return $employee->organization_id === $user->organization_id
            && $user->can('employees.invites.manage');
    }

    public function terminate(User $user, Employee $employee): bool
    {
        return $employee->organization_id === $user->organization_id
            && $user->can('employees.employment.terminate');
    }

    public function manageProjectAssignments(User $user, Employee $employee): bool
    {
        return $employee->organization_id === $user->organization_id
            && $user->can('employees.employees.manage');
    }

    private function isForemanOfEmployee(User $user, Employee $employee): bool
    {
        if (! $user->hasRole('foreman') || $employee->team_id === null) {
            return false;
        }

        return Employee::query()
            ->where('user_id', $user->id)
            ->where('id', $employee->team?->foreman_employee_id)
            ->exists();
    }

    private function ownsAForeman(User $user): bool
    {
        if (! $user->hasRole('foreman')) {
            return false;
        }

        return Employee::query()->where('user_id', $user->id)->exists();
    }
}
