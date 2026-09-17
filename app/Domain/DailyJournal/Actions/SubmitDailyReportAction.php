<?php

namespace App\Domain\DailyJournal\Actions;

use App\Domain\DailyJournal\Exceptions\DailyReportValidationException;
use App\Domain\DailyJournal\Exceptions\InvalidDailyReportStateException;
use App\Domain\DailyJournal\Exceptions\StaleDailyReportVersionException;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\DailyJournal\Services\HeadcountAttendanceService;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 11: "შევსება → წარდგენა → მენეჯერის მიღება." Only a `draft`
 * report can be submitted. Headcount-from-attendance is recomputed fresh at
 * submit time (attendance events may still have been arriving while the
 * draft was open), and a manual headcount that disagrees with attendance
 * must carry an explained variance note — never a silent, unexplained
 * override (spec: "ხელით ახსნილი სხვაობა").
 */
class SubmitDailyReportAction
{
    public function __construct(
        private readonly HeadcountAttendanceService $headcountService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(DailyReport $report, int $targetVersion, User $actor): DailyReport
    {
        if ($report->status !== 'draft') {
            throw new InvalidDailyReportStateException('submit', $report->status);
        }

        if ($report->version !== $targetVersion) {
            throw new StaleDailyReportVersionException($report->version, $targetVersion);
        }

        $freshHeadcount = $this->headcountService->computeForProjectAndDate(
            $report->project_id,
            $report->report_date,
        );

        $fieldErrors = [];

        if (blank($report->work_performed_note)) {
            $fieldErrors['work_performed_note'] = ['შესრულებული სამუშაოს აღწერა სავალდებულოა.'];
        }

        if (empty($report->teams_present)) {
            $fieldErrors['team_ids'] = ['მინიმუმ ერთი ბრიგადა უნდა იყოს მითითებული.'];
        }

        if (
            $report->headcount_manual_override !== null
            && $report->headcount_manual_override !== $freshHeadcount
            && blank($report->headcount_variance_note)
        ) {
            $fieldErrors['headcount_variance_note'] = [
                'ხელით შეყვანილი რაოდენობა დასწრების მონაცემისგან განსხვავდება — მიუთითეთ მიზეზი.',
            ];
        }

        if ($fieldErrors !== []) {
            throw new DailyReportValidationException($fieldErrors);
        }

        return DB::transaction(function () use ($report, $freshHeadcount, $actor) {
            $before = $report->getAttributes();

            $report->fill([
                'headcount_from_attendance' => $freshHeadcount,
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by_user_id' => $actor->id,
            ])->save();

            $this->auditLogger->log(
                action: 'daily_journal.report.submitted',
                target: $report,
                before: $before,
                after: $report->getAttributes(),
                actor: $actor,
            );

            return $report;
        });
    }
}
