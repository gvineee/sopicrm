<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\Exceptions\EmailDeliveryNotRetryableException;
use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use App\Domain\Timesheets\Support\TimesheetSnapshotResolver;
use App\Jobs\Timesheets\SendTimesheetEmailJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TIMESHEET-EMAIL-01: creates (or reuses) an immutable PDF snapshot for the
 * timesheet's CURRENT version, records a queued delivery row, and dispatches
 * the actual send to a queue job — this Action itself never talks to the
 * mail transport, so a slow/unavailable SMTP server never blocks the web
 * request that triggered the send.
 *
 * Snapshot reuse: an `attachments` row already exists for this exact
 * `(timesheet_id, version)` pair if this version was ever emailed before —
 * reused as-is rather than regenerated, so two deliveries of the same
 * version share byte-for-byte the same attached file. A version bump (the
 * timesheet was edited/reprocessed since the last snapshot) always
 * generates a fresh one; the old snapshot Attachment row is left in place,
 * still referenced by any OLDER delivery rows' history.
 */
class SendTimesheetEmailAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function send(Timesheet $timesheet, string $recipientEmail, ?string $recipientUserId, string $subject, User $actor): TimesheetEmailDelivery
    {
        // The job is dispatched AFTER this transaction commits (never from
        // inside it): under QUEUE_CONNECTION=sync (this codebase's own test
        // environment — phpunit.xml) a job dispatched from inside an open
        // transaction would run synchronously right there, and the job's
        // own failure-path DB writes (marking the delivery `failed`) would
        // then be rolled back along with everything else the instant it
        // threw — silently destroying the very failure record it was
        // trying to create. Even under a real async queue connection, a
        // worker could theoretically pick the job up before this
        // transaction's own commit makes the delivery row visible to it.
        // Dispatching only once the transaction has actually committed
        // avoids both hazards.
        $delivery = DB::transaction(function () use ($timesheet, $recipientEmail, $recipientUserId, $subject, $actor) {
            $snapshot = $this->resolveSnapshot($timesheet, $actor);

            $delivery = TimesheetEmailDelivery::query()->create([
                'timesheet_id' => $timesheet->id,
                'timesheet_version_at_send' => $timesheet->version,
                'attachment_id' => $snapshot->id,
                'recipient_email' => $recipientEmail,
                'recipient_user_id' => $recipientUserId,
                'subject' => $subject,
                'status' => 'queued',
                'requested_by_user_id' => $actor->id,
                'created_at' => now(),
            ]);

            $this->auditLogger->log(
                action: 'timesheets.email.queued',
                target: $delivery,
                after: $delivery->only(['recipient_email', 'timesheet_version_at_send']),
                actor: $actor,
                organizationId: $timesheet->organization_id,
            );

            return $delivery;
        });

        SendTimesheetEmailJob::dispatch($timesheet->organization_id, $delivery->id);

        return $delivery;
    }

    /**
     * Re-dispatches the SAME delivery row's send — never creates a second
     * delivery row, never regenerates the snapshot. Only a `failed`
     * delivery may be retried (see EmailDeliveryNotRetryableException).
     * Dispatches only after commit — see send()'s docblock for why.
     */
    public function retry(TimesheetEmailDelivery $delivery, User $actor): TimesheetEmailDelivery
    {
        $locked = DB::transaction(function () use ($delivery, $actor) {
            /** @var TimesheetEmailDelivery $locked */
            $locked = TimesheetEmailDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'failed') {
                throw new EmailDeliveryNotRetryableException($locked->status);
            }

            $locked->update(['status' => 'queued', 'failed_reason' => null]);

            $this->auditLogger->log(
                action: 'timesheets.email.retry_queued',
                target: $locked,
                after: $locked->only(['recipient_email']),
                actor: $actor,
                organizationId: $locked->organization_id,
            );

            return $locked;
        });

        SendTimesheetEmailJob::dispatch($locked->organization_id, $locked->id);

        return $locked;
    }

    private function resolveSnapshot(Timesheet $timesheet, User $actor): Attachment
    {
        return TimesheetSnapshotResolver::resolve($timesheet, $actor);
    }
}
