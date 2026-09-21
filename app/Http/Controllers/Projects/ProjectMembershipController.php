<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Projects\Actions\AddProjectMemberAction;
use App\Domain\Projects\Actions\RemoveProjectMemberAction;
use App\Domain\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\AddProjectMemberRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * spec section 10 project membership management — thin controller wrapping
 * App\Domain\Projects\Actions\{Add,Remove}ProjectMemberAction. Membership is
 * the write side of spec section 3's "პროექტის წევრობა და როლის უფლებები
 * ერთად განსაზღვრავს წვდომას" (see App\Policies\ProjectPolicy).
 */
class ProjectMembershipController extends Controller
{
    use AuthorizesRequests;

    public function store(AddProjectMemberRequest $request, Project $project, AddProjectMemberAction $action): RedirectResponse
    {
        $action->execute($project, (string) $request->validated('user_id'), $request->validated('role_context'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'წევრი დაემატა პროექტში.']);
    }

    public function destroy(Request $request, Project $project, ProjectMembership $membership, RemoveProjectMemberAction $action): RedirectResponse
    {
        $this->authorize('manageMemberships', $project);
        abort_unless($membership->project_id === $project->id, 404);

        $action->execute($membership, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'წევრი ამოღებულია პროექტიდან.']);
    }
}
