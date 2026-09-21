<?php

namespace App\Policies;

use App\Domain\Projects\Models\Project;
use App\Models\User;

/**
 * Reference implementation of spec section 3's "პროექტის წევრობა და
 * როლის უფლებები ერთად განსაზღვრავს წვდომას" (project membership + role
 * jointly decide access) — every later module's project-scoped Policy
 * should follow this exact shape: check the PERMISSION (via the role) AND
 * the actual ProjectMembership row, never either alone.
 *
 * `owner` is the one role the spec explicitly gives a company-wide overview
 * to (spec section 3: "კომპანიის მიმოხილვა"), so it can view/manage any
 * project in its own organization without an explicit membership row;
 * every other role — including `system_admin`, who manages users/devices/
 * settings per the spec's own role table but has no standing project-scoped
 * access — needs both the permission and the membership.
 *
 * Extended by the Projects module (this pass) beyond the original `view`/
 * `manageMemberships` pair the Auth/RBAC pass shipped as a reference
 * implementation, with the additional abilities this module's own
 * controllers need. The original two methods' behavior is unchanged.
 */
class ProjectPolicy
{
    /**
     * Company-wide project list (spec: "owner ... კომპანიის მიმოხილვა").
     * Every other role only ever sees projects it's a member of — enforced
     * by the controller scoping the index query to
     * `whereHas('memberships', ...)`, not by this ability gating a single
     * model, but `viewAny` still gates whether the "all projects" view
     * (rather than "my projects") is offered at all.
     *
     * Deliberately does NOT fall back to `projects.view` (audit finding
     * FIX-02/A2, 2026-09-21): `projects.view` is the PER-PROJECT permission
     * `view()` below pairs with an active membership check — `project_manager`
     * holds it precisely so they can open a project they belong to. Treating
     * that same permission as sufficient for org-wide `viewAny` made
     * `ProjectController::index()`'s own membership filter a no-op for any
     * role holding `projects.view` (i.e. every project_manager), so they saw
     * every project in the organization's list/search/KPIs regardless of
     * membership. Org-wide list access requires the owner/system_admin role
     * or the distinct `projects.viewAny` permission only.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('owner')
            || $user->hasRole('system_admin')
            || $user->can('projects.viewAny');
    }

    public function view(User $user, Project $project): bool
    {
        if ($project->organization_id !== $user->organization_id) {
            return false;
        }

        if ($user->hasRole('owner')) {
            return true;
        }

        return $user->can('projects.view') && $user->isActiveMemberOfProject($project->id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('owner')
            || $user->hasRole('system_admin')
            || $user->can('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        if ($project->organization_id !== $user->organization_id) {
            return false;
        }

        if (! $user->hasRole('owner')
            && ! $user->hasRole('system_admin')
            && ! $user->can('projects.update')) {
            return false;
        }

        if ($user->hasRole('owner')) {
            return true;
        }

        return $user->isActiveMemberOfProject($project->id);
    }

    public function delete(User $user, Project $project): bool
    {
        if ($project->organization_id !== $user->organization_id) {
            return false;
        }

        // Owner always has it; the permission check is a real, independent
        // path (not a redundant re-check of the same role) so a future role
        // explicitly granted `projects.delete` actually gets it — today only
        // `owner` holds that permission (ProjectsPermissionsSeeder), so
        // behavior is unchanged.
        return $user->hasRole('owner') || $user->can('projects.delete');
    }

    public function changeStatus(User $user, Project $project): bool
    {
        return $this->update($user, $project)
            && ($user->hasRole('owner') || $user->can('projects.status.change'));
    }

    public function manageMemberships(User $user, Project $project): bool
    {
        if ($project->organization_id !== $user->organization_id) {
            return false;
        }

        if (! $user->hasRole('owner') && ! $user->can('projects.memberships.manage')) {
            return false;
        }

        if ($user->hasRole('owner') || $user->hasRole('system_admin')) {
            return true;
        }

        return $user->isActiveMemberOfProject($project->id);
    }

    public function manageWbs(User $user, Project $project): bool
    {
        if ($project->organization_id !== $user->organization_id) {
            return false;
        }

        if (! $user->hasRole('owner') && ! $user->can('projects.wbs.manage')) {
            return false;
        }

        if ($user->hasRole('owner')) {
            return true;
        }

        return $user->isActiveMemberOfProject($project->id);
    }

    public function manageDocuments(User $user, Project $project): bool
    {
        if ($project->organization_id !== $user->organization_id) {
            return false;
        }

        if (! $user->hasRole('owner') && ! $user->can('projects.documents.manage')) {
            return false;
        }

        if ($user->hasRole('owner')) {
            return true;
        }

        return $user->isActiveMemberOfProject($project->id);
    }

    /**
     * Spec section 3 explicit hard rule + section 23 mandatory test row:
     * a system admin never gets financial access automatically, and the
     * budget_baseline figure is treated as financial data for this purpose.
     * Client/subcontractor visibility is likewise excluded by omission
     * (they never hold `projects.budget.view`) — spec: "თვითღირებულება და
     * ხელფასები დამალულია" for that role.
     */
    public function viewBudget(User $user, Project $project): bool
    {
        if (! $this->view($user, $project)) {
            return false;
        }

        if ($user->hasRole('owner')) {
            return true;
        }

        return $user->can('projects.budget.view');
    }
}
