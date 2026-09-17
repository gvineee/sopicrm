<?php

namespace App\Domain\DailyJournal\Actions;

use App\Domain\DailyJournal\Exceptions\StaleDailyReportVersionException;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\DailyJournal\Models\DailyReportRevision;
use App\Domain\DailyJournal\Models\DailyReportTaskLink;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 11 hard rule: "დახურული დღის რედაქტირება ქმნის revision-ს"
 * (editing a CLOSED/accepted day creates a revision — append, never
 * overwrite). Editing while still `draft` or `submitted` is a plain update
 * with no revision, since the day isn't closed yet.
 */
class UpdateDailyReportDraftAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $taskIds  Null leaves existing links untouched.
     */
    public function execute(DailyReport $report, array $data, ?array $taskIds, int $targetVersion, User $actor, ?string $reason = null): DailyReport
    {
        if ($report->version !== $targetVersion) {
            throw new StaleDailyReportVersionException($report->version, $targetVersion);
        }

        return DB::transaction(function () use ($report, $data, $taskIds, $actor, $reason) {
            $wasAccepted = $report->status === 'accepted';

            if ($wasAccepted) {
                // Append-only snapshot of the pre-edit state — never
                // overwritten, never deleted (spec 11 explicit).
                DailyReportRevision::create([
                    'daily_report_id' => $report->id,
                    'snapshot' => $report->getAttributes(),
                    'revised_by_user_id' => $actor->id,
                    'revised_at' => now(),
                    'reason' => $reason,
                ]);
            }

            $before = $report->getAttributes();

            $report->fill([
                'responsible_user_id' => $data['responsible_user_id'] ?? $report->responsible_user_id,
                'teams_present' => $data['team_ids'] ?? $report->teams_present,
                'headcount_manual_override' => array_key_exists('headcount_manual_override', $data)
                    ? $data['headcount_manual_override']
                    : $report->headcount_manual_override,
                'headcount_variance_note' => $data['headcount_variance_note'] ?? $report->headcount_variance_note,
                'work_performed_note' => $data['work_performed_note'] ?? $report->work_performed_note,
                'equipment_used' => $data['equipment_used'] ?? $report->equipment_used,
                'materials_received_note' => $data['materials_received_note'] ?? $report->materials_received_note,
                'delays_note' => $data['delays_note'] ?? $report->delays_note,
                'quality_safety_note' => $data['quality_safety_note'] ?? $report->quality_safety_note,
                'photo_attachment_ids' => $data['photo_attachment_ids'] ?? $report->photo_attachment_ids,
                'next_day_plan' => $data['next_day_plan'] ?? $report->next_day_plan,
                'weather_manual' => $data['weather_manual'] ?? $report->weather_manual,
            ])->save();

            if ($taskIds !== null) {
                $this->syncTaskLinks($report, $taskIds);
            }

            $this->auditLogger->log(
                action: $wasAccepted ? 'daily_journal.report.revised' : 'daily_journal.report.updated',
                target: $report,
                before: $before,
                after: $report->getAttributes(),
                reason: $reason,
                actor: $actor,
            );

            return $report->fresh(['taskLinks']);
        });
    }

    /**
     * @param  list<string>  $taskIds
     */
    private function syncTaskLinks(DailyReport $report, array $taskIds): void
    {
        $projectId = $report->project_id;
        $desired = array_values(array_unique($taskIds));

        $validTaskIds = Task::query()
            ->whereIn('id', $desired)
            ->where('project_id', $projectId)
            ->pluck('id')
            ->all();

        DailyReportTaskLink::query()
            ->where('daily_report_id', $report->id)
            ->whereNotIn('task_id', $validTaskIds)
            ->delete();

        $existing = DailyReportTaskLink::query()
            ->where('daily_report_id', $report->id)
            ->pluck('task_id')
            ->all();

        foreach (array_diff($validTaskIds, $existing) as $taskId) {
            DailyReportTaskLink::create([
                'daily_report_id' => $report->id,
                'task_id' => $taskId,
            ]);
        }
    }
}
