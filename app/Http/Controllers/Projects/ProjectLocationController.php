<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\Actions\CreateProjectLocationAction;
use App\Domain\Projects\Actions\DeleteProjectLocationAction;
use App\Domain\Projects\Actions\UpdateProjectLocationAction;
use App\Domain\Projects\Exceptions\ProjectDomainException;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectLocation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectLocationRequest;
use App\Http\Requests\Projects\UpdateProjectLocationRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * spec section 10 optional WBS (project -> corpus/zone -> floor -> space —
 * every level skippable). Thin controller over App\Domain\Projects\Actions\
 * {Create,Update,Delete}ProjectLocationAction.
 */
class ProjectLocationController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreProjectLocationRequest $request, Project $project, CreateProjectLocationAction $action): RedirectResponse
    {
        try {
            $action->execute(
                $project,
                (string) $request->validated('level_type'),
                (string) $request->validated('name'),
                $request->validated('parent_location_id'),
                $request->user(),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'ლოკაცია დაემატა.']);
    }

    public function update(UpdateProjectLocationRequest $request, Project $project, ProjectLocation $location, UpdateProjectLocationAction $action): RedirectResponse
    {
        abort_unless($location->project_id === $project->id, 404);

        try {
            $action->execute(
                $location,
                (string) $request->validated('level_type'),
                (string) $request->validated('name'),
                $request->validated('parent_location_id'),
                $request->user(),
            );
        } catch (ProjectDomainException $e) {
            return back()->withErrors($e->fieldErrors());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'ლოკაცია განახლდა.']);
    }

    public function destroy(Request $request, Project $project, ProjectLocation $location, DeleteProjectLocationAction $action): RedirectResponse
    {
        $this->authorize('manageWbs', $project);
        abort_unless($location->project_id === $project->id, 404);

        try {
            $action->execute($location, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'ლოკაცია წაიშალა.']);
    }
}
