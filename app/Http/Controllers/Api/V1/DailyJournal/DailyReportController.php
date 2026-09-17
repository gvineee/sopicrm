<?php

namespace App\Http\Controllers\Api\V1\DailyJournal;

use App\Domain\DailyJournal\Actions\AcceptDailyReportAction;
use App\Domain\DailyJournal\Actions\CreateDailyReportDraftAction;
use App\Domain\DailyJournal\Actions\ReturnDailyReportAction;
use App\Domain\DailyJournal\Actions\SubmitDailyReportAction;
use App\Domain\DailyJournal\Actions\UpdateDailyReportDraftAction;
use App\Domain\DailyJournal\Exceptions\DailyReportValidationException;
use App\Domain\DailyJournal\Exceptions\DuplicateDailyReportException;
use App\Domain\DailyJournal\Exceptions\InvalidDailyReportStateException;
use App\Domain\DailyJournal\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\DailyJournal\Exceptions\StaleDailyReportVersionException;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\DailyJournal\AcceptDailyReportRequest;
use App\Http\Requests\DailyJournal\ReturnDailyReportRequest;
use App\Http\Requests\DailyJournal\StoreDailyReportRequest;
use App\Http\Requests\DailyJournal\SubmitDailyReportRequest;
use App\Http\Requests\DailyJournal\UpdateDailyReportRequest;
use App\Http\Resources\DailyJournal\DailyReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1 JSON surface for this module — spec section 18: "Inertia
 * ჩვეულებრივი გვერდებისთვის; /api/v1 ... მობილური offline queue-ის ...
 * საჭიროებისთვის." Same Domain Actions and Policies as the Inertia
 * controller (one domain layer, one set of authorization rules, per spec).
 * Submission-mutating POSTs (`store`, `submit`) require the `idempotency`
 * middleware (see routes/modules/api-dailyjournal.php) per spec section 20.
 */
class DailyReportController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('viewAny', [DailyReport::class, $project]);

        $reports = DailyReport::query()
            ->where('project_id', $project->id)
            ->with(['responsible'])
            ->orderByDesc('report_date')
            ->paginate((int) $request->query('limit', 20));

        return DailyReportResource::collection($reports)->response();
    }

    public function show(Project $project, DailyReport $report): JsonResponse
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('view', $report);

        $report->load(['responsible', 'submittedBy', 'acceptedBy', 'taskLinks.task']);

        return (new DailyReportResource($report))->response();
    }

    public function store(StoreDailyReportRequest $request, Project $project, CreateDailyReportDraftAction $action): JsonResponse
    {
        $this->authorize('create', [DailyReport::class, $project]);

        try {
            $report = $action->execute(
                $project,
                $request->safe()->except(['team_ids', 'task_ids']) + ['team_ids' => $request->input('team_ids', [])],
                $request->input('task_ids', []),
                $request->user(),
            );
        } catch (DuplicateDailyReportException $exception) {
            return $this->errorResponse($request, 409, 'daily_report_duplicate', $exception->getMessage());
        }

        return (new DailyReportResource($report))->response()->setStatusCode(201);
    }

    public function update(UpdateDailyReportRequest $request, Project $project, DailyReport $report, UpdateDailyReportDraftAction $action): JsonResponse
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('update', $report);

        $data = $request->safe()->except(['target_version', 'reason', 'team_ids', 'task_ids']);
        if ($request->has('team_ids')) {
            $data['team_ids'] = $request->input('team_ids');
        }

        try {
            $report = $action->execute(
                $report,
                $data,
                $request->has('task_ids') ? $request->input('task_ids') : null,
                (int) $request->input('target_version'),
                $request->user(),
                $request->input('reason'),
            );
        } catch (StaleDailyReportVersionException $exception) {
            return $this->errorResponse($request, 409, 'stale_version', $exception->getMessage(), [
                'currentVersion' => $exception->currentVersion,
            ]);
        }

        return (new DailyReportResource($report))->response();
    }

    public function submit(SubmitDailyReportRequest $request, Project $project, DailyReport $report, SubmitDailyReportAction $action): JsonResponse
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('submit', $report);

        try {
            $report = $action->execute($report, (int) $request->input('target_version'), $request->user());
        } catch (DailyReportValidationException $exception) {
            return response()->json([
                'code' => 'validation_failed',
                'message' => 'ჟურნალის ჩანაწერი წარსადგენად არასრულია.',
                'fieldErrors' => $exception->fieldErrors,
                'requestId' => $request->attributes->get('request_id'),
            ], 422);
        } catch (InvalidDailyReportStateException $exception) {
            return $this->errorResponse($request, 409, 'invalid_state', $exception->getMessage());
        } catch (StaleDailyReportVersionException $exception) {
            return $this->errorResponse($request, 409, 'stale_version', $exception->getMessage(), [
                'currentVersion' => $exception->currentVersion,
            ]);
        }

        return (new DailyReportResource($report))->response();
    }

    public function accept(AcceptDailyReportRequest $request, Project $project, DailyReport $report, AcceptDailyReportAction $action): JsonResponse
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('accept', $report);

        try {
            $report = $action->execute($report, (int) $request->input('target_version'), $request->user(), $request->input('notes'));
        } catch (SelfApprovalNotAllowedException $exception) {
            return $this->errorResponse($request, 403, 'self_approval_not_allowed', $exception->getMessage());
        } catch (InvalidDailyReportStateException $exception) {
            return $this->errorResponse($request, 409, 'invalid_state', $exception->getMessage());
        } catch (StaleDailyReportVersionException $exception) {
            return $this->errorResponse($request, 409, 'stale_version', $exception->getMessage(), [
                'currentVersion' => $exception->currentVersion,
            ]);
        }

        return (new DailyReportResource($report))->response();
    }

    public function return(ReturnDailyReportRequest $request, Project $project, DailyReport $report, ReturnDailyReportAction $action): JsonResponse
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('return', $report);

        try {
            $report = $action->execute($report, (int) $request->input('target_version'), $request->user(), $request->input('reason'));
        } catch (InvalidDailyReportStateException $exception) {
            return $this->errorResponse($request, 409, 'invalid_state', $exception->getMessage());
        } catch (StaleDailyReportVersionException $exception) {
            return $this->errorResponse($request, 409, 'stale_version', $exception->getMessage(), [
                'currentVersion' => $exception->currentVersion,
            ]);
        }

        return (new DailyReportResource($report))->response();
    }

    private function ensureReportBelongsToProject(Project $project, DailyReport $report): void
    {
        abort_unless($report->project_id === $project->id, 404);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function errorResponse(Request $request, int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'fieldErrors' => [],
            'requestId' => $request->attributes->get('request_id'),
            ...$extra,
        ], $status);
    }
}
