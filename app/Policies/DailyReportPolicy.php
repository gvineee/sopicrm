<?php

namespace App\Policies;

use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\Projects\Models\Project;
use App\Models\User;

/**
 * Spec section 3: role + project membership jointly decide access (see
 * app/Policies/ProjectPolicy.php for the reference shape this follows).
 * `owner` has company-wide overview (view/accept anywhere in its own
 * organization); every other role needs both the permission AND an active
 * `ProjectMembership` on the report's project — a permission alone is never
 * enough for a project-scoped resource.
 */
class DailyReportPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->hasProjectAccess($user, $project, 'dailyjournal.reports.view');
    }

    public function view(User $user, DailyReport $report): bool
    {
        if ($report->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->hasProjectAccess($user, $report->project, 'dailyjournal.reports.view');
    }

    public function create(User $user, Project $project): bool
    {
        return $this->hasProjectAccess($user, $project, 'dailyjournal.reports.create');
    }

    public function update(User $user, DailyReport $report): bool
    {
        if ($report->organization_id !== $user->organization_id) {
            return false;
        }

        if ($report->status === 'accepted' && ! $user->can('dailyjournal.reports.revise-accepted')) {
            return false;
        }

        return $this->hasProjectAccess($user, $report->project, 'dailyjournal.reports.update');
    }

    public function submit(User $user, DailyReport $report): bool
    {
        if ($report->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->hasProjectAccess($user, $report->project, 'dailyjournal.reports.submit');
    }

    public function accept(User $user, DailyReport $report): bool
    {
        if ($report->organization_id !== $user->organization_id) {
            return false;
        }

        // Self-approval is additionally blocked at the Domain Action layer
        // (SelfApprovalNotAllowedException) regardless of this Policy's
        // answer — this check is the visible "can they even try" gate, that
        // one is the real, unconditional server-side rule.
        return $this->hasProjectAccess($user, $report->project, 'dailyjournal.reports.accept');
    }

    public function return(User $user, DailyReport $report): bool
    {
        if ($report->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->hasProjectAccess($user, $report->project, 'dailyjournal.reports.return');
    }

    private function hasProjectAccess(User $user, Project $project, string $permission): bool
    {
        if ($project->organization_id !== $user->organization_id) {
            return false;
        }

        if (! $user->can($permission)) {
            return false;
        }

        if ($user->hasRole('owner')) {
            return true;
        }

        return $user->isActiveMemberOfProject($project->id);
    }
}
