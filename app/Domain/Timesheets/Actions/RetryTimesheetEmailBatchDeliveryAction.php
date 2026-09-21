<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\Exceptions\EmailDeliveryNotRetryableException;
use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use App\Jobs\Timesheets\SendBundledTimesheetEmailJob;
use App\Jobs\Timesheets\SendTimesheetEmailJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TIMESHEET-EMAIL-02: the batch-aware sibling of
 * App\Domain\Timesheets\Actions\SendTimesheetEmailAction::retry() — that
 * method stays as the single-send-only path (unchanged, still used by
 * TimesheetController::emailRetry()). This one is used by the new
 * batch-delivery retry route: same "only a `failed`, non-cancelled delivery
 * may be retried, never creates a new row, never regenerates any snapshot"
 * contract, but picks the correct job class (per-timesheet vs bundled)
 * based on whether the delivery has any TimesheetEmailDeliveryItem rows.
 */
class RetryTimesheetEmailBatchDeliveryAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(TimesheetEmailDelivery $delivery, User $actor): TimesheetEmailDelivery
    {
        $locked = DB::transaction(function () use ($delivery, $actor) {
            /** @var TimesheetEmailDelivery $locked */
            $locked = TimesheetEmailDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'failed') {
                throw new EmailDeliveryNotRetryableException($locked->status);
            }

            $locked->update(['status' => 'queued', 'failed_reason' => null, 'cancelled_at' => null]);

            $this->auditLogger->log(
                action: 'timesheets.email_batch.delivery_retry_queued',
                target: $locked,
                after: $locked->only(['recipient_email']),
                actor: $actor,
                organizationId: $locked->organization_id,
            );

            return $locked;
        });

        $isBundled = $locked->items()->exists();
        $jobClass = $isBundled ? SendBundledTimesheetEmailJob::class : SendTimesheetEmailJob::class;
        $jobClass::dispatch($locked->organization_id, $locked->id);

        return $locked;
    }
}
