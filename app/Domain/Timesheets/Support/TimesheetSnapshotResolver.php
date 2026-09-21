<?php

namespace App\Domain\Timesheets\Support;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Timesheets\Actions\GenerateTimesheetPdfAction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TIMESHEET-EMAIL-02: extracted, behavior-identical, from
 * App\Domain\Timesheets\Actions\SendTimesheetEmailAction::resolveSnapshot()
 * (TIMESHEET-EMAIL-01) so App\Domain\Timesheets\Actions\
 * CreateTimesheetEmailBatchAction can reuse the exact same
 * generate-or-reuse-by-version snapshot logic instead of duplicating it.
 * SendTimesheetEmailAction now delegates here; its own public behavior and
 * existing tests are unchanged.
 */
class TimesheetSnapshotResolver
{
    public static function resolve(Timesheet $timesheet, User $actor): Attachment
    {
        $existing = Attachment::query()
            ->where('owner_type', Timesheet::class)
            ->where('owner_id', $timesheet->id)
            ->where('status', 'available')
            ->where('classification', 'timesheet_snapshot_v'.$timesheet->version)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $pdf = app(GenerateTimesheetPdfAction::class)->execute($timesheet);
        $bytes = $pdf->output();

        $storagePath = sprintf('timesheets/%s/%s.pdf', $timesheet->id, (string) Str::uuid());
        Storage::disk('private')->put($storagePath, $bytes);

        return Attachment::create([
            'owner_type' => Timesheet::class,
            'owner_id' => $timesheet->id,
            'disk' => 'private',
            'storage_path' => $storagePath,
            'original_filename' => "tabeli-{$timesheet->id}-v{$timesheet->version}.pdf",
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'status' => 'available',
            'uploaded_by_user_id' => $actor->id,
            'classification' => 'timesheet_snapshot_v'.$timesheet->version,
        ]);
    }
}
