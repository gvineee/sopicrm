<?php

namespace App\Domain\DailyJournal\Actions;

use App\Domain\DailyJournal\Exceptions\DuplicateDailyReportException;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\DailyJournal\Models\DailyReportTaskLink;
use App\Domain\DailyJournal\Services\HeadcountAttendanceService;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 11 form: project/date, responsible person, brigades,
 * attendance-derived headcount (+ manually explained variance), work/volume,
 * equipment, material receipts, delays, quality/safety issues, photos,
 * next-day plan. Weather is manual for now.
 */
class CreateDailyReportDraftAction
{
    public function __construct(
        private readonly HeadcountAttendanceService $headcountService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated StoreDailyReportRequest data. Never contains
     *                                      organization_id/status/version — those are never
     *                                      client-controlled (hard constraint).
     * @param  list<string>  $taskIds  Task IDs to link by reference (see DailyReportTaskLink).
     */
    public function execute(Project $project, array $data, array $taskIds, User $actor): DailyReport
    {
        $existing = DailyReport::query()
            ->where('project_id', $project->id)
            ->where('report_date', $data['report_date'])
            ->first();

        if ($existing !== null) {
            throw new DuplicateDailyReportException($existing->id);
        }

        $headcountFromAttendance = $this->headcountService->computeForProjectAndDate(
            $project->id,
            $data['report_date'],
        );

        return DB::transaction(function () use ($project, $data, $taskIds, $actor, $headcountFromAttendance) {
            $report = DailyReport::create([
                'project_id' => $project->id,
                'report_date' => $data['report_date'],
                'responsible_user_id' => $data['responsible_user_id'],
                'teams_present' => $data['team_ids'] ?? [],
                'headcount_from_attendance' => $headcountFromAttendance,
                'headcount_manual_override' => $data['headcount_manual_override'] ?? null,
                'headcount_variance_note' => $data['headcount_variance_note'] ?? null,
                'work_performed_note' => $data['work_performed_note'] ?? null,
                'equipment_used' => $data['equipment_used'] ?? [],
                'materials_received_note' => $data['materials_received_note'] ?? null,
                'delays_note' => $data['delays_note'] ?? null,
                'quality_safety_note' => $data['quality_safety_note'] ?? null,
                'photo_attachment_ids' => $data['photo_attachment_ids'] ?? [],
                'next_day_plan' => $data['next_day_plan'] ?? null,
                'weather_manual' => $data['weather_manual'] ?? null,
                'status' => 'draft',
            ]);

            $this->syncTaskLinks($report, $taskIds, $project->id);

            $this->auditLogger->log(
                action: 'daily_journal.report.created',
                target: $report,
                after: $report->getAttributes(),
                actor: $actor,
            );

            return $report;
        });
    }

    /**
     * @param  list<string>  $taskIds
     */
    private function syncTaskLinks(DailyReport $report, array $taskIds, string $projectId): void
    {
        foreach (array_unique($taskIds) as $taskId) {
            // Existence + tenant/project consistency re-checked server-side —
            // a client-supplied task id from another project is silently
            // ignored, never linked (never trust a client-supplied
            // relationship without re-validating it, same principle as
            // organization_id derivation).
            $belongsToProject = Task::query()->whereKey($taskId)->where('project_id', $projectId)->exists();

            if (! $belongsToProject) {
                continue;
            }

            DailyReportTaskLink::create([
                'daily_report_id' => $report->id,
                'task_id' => $taskId,
            ]);
        }
    }
}
