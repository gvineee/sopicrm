<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\Actions\DeleteProjectDocumentAction;
use App\Domain\Projects\Actions\UploadProjectDocumentAction;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UploadProjectDocumentRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * spec section 10 project documents — thin controller over
 * App\Domain\Projects\Actions\{Upload,Delete}ProjectDocumentAction. Files
 * live on the private disk; every download re-checks the real Policy
 * server-side (hard constraint: a signed/guessable URL alone is never the
 * access boundary) rather than serving a public path.
 */
class ProjectDocumentController extends Controller
{
    use AuthorizesRequests;

    public function store(UploadProjectDocumentRequest $request, Project $project, UploadProjectDocumentAction $action): RedirectResponse
    {
        try {
            $action->execute($project, $request->file('file'), $request->validated('caption'), $request->validated('classification'), $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'დოკუმენტი აიტვირთა.']);
    }

    public function download(Request $request, Project $project, Attachment $document): StreamedResponse
    {
        // Any project member who can see the project at all can view its
        // documents (drawings, contracts) — uploading/deleting is the
        // narrower `manageDocuments` ability, checked separately below.
        $this->authorize('view', $project);
        abort_unless($document->owner_type === $project->getMorphClass() && $document->owner_id === $project->id, 404);

        return Storage::disk($document->disk)->download($document->storage_path, $document->original_filename);
    }

    public function destroy(Request $request, Project $project, Attachment $document, DeleteProjectDocumentAction $action): RedirectResponse
    {
        $this->authorize('manageDocuments', $project);
        abort_unless($document->owner_type === $project->getMorphClass() && $document->owner_id === $project->id, 404);

        $action->execute($document, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დოკუმენტი წაიშალა.']);
    }
}
