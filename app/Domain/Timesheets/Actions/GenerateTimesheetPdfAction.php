<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Auth\Models\Organization;
use App\Domain\Timesheets\Support\TimesheetPdfFonts;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfInstance;

/**
 * TIMESHEET-01: renders an on-demand, point-in-time PDF snapshot of a
 * Timesheet. Deliberately NOT persisted anywhere by this Action — every
 * call re-renders fresh from the Timesheet's CURRENT database state, which
 * is fine for a live "view/download" action (the returned PDF always
 * reflects the version at generation time, and the PDF's own footer prints
 * that version + a generation timestamp so two downloads of an edited
 * Timesheet are visibly distinguishable). TIMESHEET-EMAIL-01 (not yet
 * built) is the ticket that needs an immutably STORED snapshot for an
 * email attachment — it must add its own persistence, this Action does not
 * provide it.
 */
class GenerateTimesheetPdfAction
{
    public function execute(Timesheet $timesheet): PdfInstance
    {
        TimesheetPdfFonts::register();

        $timesheet->loadMissing(['employee', 'payPeriod', 'lines.project']);

        $organization = Organization::query()->findOrFail($timesheet->organization_id);

        $statusLabels = [
            'draft' => 'მონახაზი',
            'submitted' => 'წარდგენილი',
            'approved' => 'დამტკიცებული',
            'locked' => 'დახურული',
            'rejected' => 'დაბრუნებული',
        ];

        $lines = $timesheet->lines->map(fn ($line) => [
            'work_date' => $line->work_date->toDateString(),
            'project_name' => $line->project?->name,
            'rate_type' => $line->rate_type,
            'hours' => round($line->payable_minutes / 60, 2),
        ])->all();

        $totalHours = round($timesheet->lines->sum('payable_minutes') / 60, 2);

        $pdf = Pdf::loadView('pdf.timesheet', [
            'organizationName' => $organization->name,
            'employeeName' => trim($timesheet->employee->first_name.' '.$timesheet->employee->last_name),
            'periodStart' => $timesheet->payPeriod->starts_on->toDateString(),
            'periodEnd' => $timesheet->payPeriod->ends_on->toDateString(),
            'statusLabel' => $statusLabels[$timesheet->status],
            'version' => $timesheet->version,
            'approvedAt' => $timesheet->approved_at?->toDateTimeString(),
            'rejectedReason' => $timesheet->status === 'rejected' ? $timesheet->rejected_reason : null,
            'lines' => $lines,
            'totalHours' => $totalHours,
            'generatedAt' => now()->toDateTimeString(),
            'timesheetId' => $timesheet->id,
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }
}
