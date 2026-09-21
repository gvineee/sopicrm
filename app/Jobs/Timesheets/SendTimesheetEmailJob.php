<?php

namespace App\Jobs\Timesheets;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use App\Mail\TimesheetSnapshotMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * TIMESHEET-EMAIL-01. Unlike App\Jobs\Shared\ProcessOutboxEventJob (QUEUE-01),
 * this job's dispatcher (App\Domain\Timesheets\Actions\SendTimesheetEmailAction)
 * already knows the organization id at dispatch time — a real, authenticated
 * web request created the delivery row — so no cross-tenant escape-hatch
 * lookup is needed; the org id travels in the job's own constructor and is
 * applied directly, before the delivery row is even read.
 *
 * Re-derives tenant context itself because a queue worker process has no
 * ambient request context (docs/architecture.md §4) — never relies on
 * anything set by the request that dispatched it.
 */
class SendTimesheetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $deliveryId,
    ) {}

    public function handle(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        // Saved/restored rather than unconditionally cleared in `finally`:
        // under a real async queue connection this job runs in its own
        // worker process with no other ambient context (previous value is
        // always null, so restoring it is equivalent to clearing). Under
        // QUEUE_CONNECTION=sync (this codebase's own test environment —
        // phpunit.xml), dispatch() runs this job's handle() synchronously
        // INSIDE whatever request/test already had its own
        // CurrentOrganization set — unconditionally clearing here would
        // silently wipe out that caller's own tenant context the instant
        // this job finishes, for the rest of that same request.
        $previousOrganizationId = CurrentOrganization::id();

        try {
            CurrentOrganization::set($this->organizationId);

            if ($isPgsql) {
                DB::statement("select set_config('app.current_org_id', ?, false)", [$this->organizationId]);
            }

            /** @var TimesheetEmailDelivery $delivery */
            $delivery = TimesheetEmailDelivery::query()->with('attachment')->findOrFail($this->deliveryId);

            if ($delivery->status !== 'queued' || $delivery->isCancelled()) {
                // Idempotency: a redelivered job (queue at-least-once
                // semantics) for a delivery that already finished (sent or
                // failed-and-not-yet-retried) is a safe no-op, never a
                // duplicate send. TIMESHEET-EMAIL-02: a batch delivery
                // cancelled after being queued (still `status = 'queued'`,
                // see that migration's docblock) is the same kind of no-op —
                // always null for a plain TIMESHEET-EMAIL-01 single send, so
                // this never changes that path's behavior.
                return;
            }

            /** @var Timesheet $timesheet */
            $timesheet = Timesheet::query()->with('employee')->findOrFail($delivery->timesheet_id);

            Mail::to($delivery->recipient_email)->send(new TimesheetSnapshotMail(
                timesheet: $timesheet,
                snapshot: $delivery->attachment,
                emailSubject: $delivery->subject,
                employeeName: trim($timesheet->employee->first_name.' '.$timesheet->employee->last_name),
                periodStart: $timesheet->payPeriod?->starts_on?->toDateString() ?? '',
                periodEnd: $timesheet->payPeriod?->ends_on?->toDateString() ?? '',
            ));

            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            if (isset($delivery)) {
                // Never persist the raw exception message verbatim into a
                // column readable from the UI — it can carry SMTP
                // host/credential fragments from the underlying transport
                // exception. Truncate and keep only the exception's own
                // short message.
                $delivery->update([
                    'status' => 'failed',
                    'failed_reason' => mb_substr($exception->getMessage(), 0, 500),
                ]);
            }

            throw $exception;
        } finally {
            CurrentOrganization::set($previousOrganizationId);

            if ($isPgsql) {
                DB::statement(
                    "select set_config('app.current_org_id', ?, false)",
                    [$previousOrganizationId ?? '']
                );
            }
        }
    }
}
