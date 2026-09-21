<?php

namespace App\Jobs\Timesheets;

use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use App\Mail\TimesheetBundleSnapshotMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * TIMESHEET-EMAIL-02: the `bundled`-mode sibling of App\Jobs\Timesheets\
 * SendTimesheetEmailJob — same idempotency/tenant-context/failure-recording
 * pattern (copied deliberately rather than sharing a base class, to keep
 * TIMESHEET-EMAIL-01's own job untouched and independently reviewable), but
 * sends App\Mail\TimesheetBundleSnapshotMail built from this delivery's
 * `items()` instead of a single timesheet/attachment pair.
 */
class SendBundledTimesheetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $deliveryId,
    ) {}

    public function handle(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        $previousOrganizationId = CurrentOrganization::id();

        try {
            CurrentOrganization::set($this->organizationId);

            if ($isPgsql) {
                DB::statement("select set_config('app.current_org_id', ?, false)", [$this->organizationId]);
            }

            /** @var TimesheetEmailDelivery $delivery */
            $delivery = TimesheetEmailDelivery::query()->with('items.attachment')->findOrFail($this->deliveryId);

            if ($delivery->status !== 'queued' || $delivery->isCancelled()) {
                return;
            }

            if ($delivery->items->isEmpty()) {
                // Defensive: CreateTimesheetEmailBatchAction never creates a
                // bundled delivery with zero items, but a job must never
                // silently "succeed" sending an empty envelope if this
                // invariant is ever violated.
                throw new \RuntimeException('Bundled timesheet email delivery has no items to attach.');
            }

            Mail::to($delivery->recipient_email)->send(new TimesheetBundleSnapshotMail(
                items: $delivery->items,
                emailSubject: $delivery->subject,
                recipientLabel: $delivery->recipient_email,
            ));

            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            if (isset($delivery)) {
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
