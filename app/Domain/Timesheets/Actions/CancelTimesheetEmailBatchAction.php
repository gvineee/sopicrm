<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\Models\TimesheetEmailBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TIMESHEET-EMAIL-02: cancels only the still-`queued`, not-yet-cancelled
 * deliveries of a batch — never an already `sent`/`failed` one, and never
 * describes an already-sent email as cancelled (the ticket's own explicit
 * warning). A delivery already claimed by a running job before this commits
 * still completes normally: App\Jobs\Timesheets\SendTimesheetEmailJob /
 * SendBundledTimesheetEmailJob re-check `cancelled_at` themselves right
 * before sending, so the only guarantee here is "no NEW send starts for a
 * cancelled delivery," not "an in-flight send is aborted mid-flight" (mail
 * sending has no cancellation point once started).
 */
class CancelTimesheetEmailBatchAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(TimesheetEmailBatch $batch, User $actor): TimesheetEmailBatch
    {
        DB::transaction(function () use ($batch, $actor) {
            $cancelledCount = $batch->deliveries()
                ->where('status', 'queued')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()]);

            $batch->update(['status' => 'cancelled']);

            $this->auditLogger->log(
                action: 'timesheets.email_batch.cancelled',
                target: $batch,
                after: ['cancelled_deliveries' => $cancelledCount],
                actor: $actor,
                organizationId: $batch->organization_id,
            );
        });

        return $batch->fresh();
    }
}
