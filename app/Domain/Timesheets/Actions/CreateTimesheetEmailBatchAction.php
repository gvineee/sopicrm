<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\Models\TimesheetEmailBatch;
use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use App\Domain\Timesheets\Models\TimesheetEmailDeliveryItem;
use App\Domain\Timesheets\Support\TimesheetSnapshotResolver;
use App\Jobs\Timesheets\SendBundledTimesheetEmailJob;
use App\Jobs\Timesheets\SendTimesheetEmailJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TIMESHEET-EMAIL-02: creates a TimesheetEmailBatch plus its
 * TimesheetEmailDelivery row(s) from a set of selected timesheet ids, then
 * dispatches one send job per delivery after the creating transaction
 * commits (same hazard TIMESHEET-EMAIL-01's SendTimesheetEmailAction
 * avoids — see its own docblock).
 *
 * `per_employee` mode creates ONE delivery PER SELECTED TIMESHEET, grouped
 * by employee only for the skip/authorization pass — each employee's
 * material is still never mixed with another's (the ticket's own hard
 * requirement), but an employee with two selected timesheets receives two
 * separate emails rather than one email with two attachments. This is a
 * deliberate scope simplification: it lets `per_employee` mode reuse
 * TIMESHEET-EMAIL-01's existing SendTimesheetEmailAction-equivalent
 * single-attachment path (App\Jobs\Timesheets\SendTimesheetEmailJob)
 * completely unchanged, with zero risk to its own passing tests. Only
 * `bundled` mode (explicitly required to be ONE message to ONE recipient
 * carrying the whole selected set) uses the new multi-item
 * TimesheetEmailDeliveryItem/SendBundledTimesheetEmailJob path.
 *
 * Every selected timesheet id is resolved and authorization-checked INSIDE
 * this Action, at batch-creation time — never trusted from the request
 * beyond "these are the ids the client asked for." A timesheet that
 * doesn't exist, belongs to a different organization, or the actor cannot
 * `send` is recorded in `skipped_details` with an explicit reason and
 * excluded from every delivery, never silently dropped.
 */
class CreateTimesheetEmailBatchAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<string>  $timesheetIds
     */
    public function execute(
        array $timesheetIds,
        string $mode,
        ?string $bundledRecipientEmail,
        ?string $bundledRecipientUserId,
        User $actor,
    ): TimesheetEmailBatch {
        if (! in_array($mode, ['per_employee', 'bundled'], true)) {
            throw new InvalidArgumentException("Unknown batch mode: {$mode}");
        }

        if ($mode === 'bundled' && ($bundledRecipientEmail === null || $bundledRecipientEmail === '')) {
            throw new InvalidArgumentException('Bundled mode requires a recipient email.');
        }

        [$batch, $deliveries] = DB::transaction(function () use ($timesheetIds, $mode, $bundledRecipientEmail, $bundledRecipientUserId, $actor) {
            [$valid, $skipped] = $this->resolveAndAuthorize($timesheetIds, $actor);

            $batch = TimesheetEmailBatch::query()->create([
                'mode' => $mode,
                'status' => $valid === [] ? 'completed' : 'processing',
                'requested_by_user_id' => $actor->id,
                'bundled_recipient_email' => $mode === 'bundled' ? $bundledRecipientEmail : null,
                'bundled_recipient_user_id' => $mode === 'bundled' ? $bundledRecipientUserId : null,
                'total_count' => count($timesheetIds),
                'skipped_details' => $skipped === [] ? null : $skipped,
                'created_at' => now(),
            ]);

            $deliveries = $mode === 'bundled'
                ? $this->createBundledDelivery($batch, $valid, $bundledRecipientEmail, $bundledRecipientUserId, $actor)
                : $this->createPerEmployeeDeliveries($batch, $valid, $actor);

            $this->auditLogger->log(
                action: 'timesheets.email_batch.created',
                target: $batch,
                after: $batch->only(['mode', 'total_count']),
                actor: $actor,
                organizationId: $actor->organization_id,
            );

            return [$batch, $deliveries];
        });

        foreach ($deliveries as [$job, $delivery]) {
            $job::dispatch($delivery->organization_id, $delivery->id);
        }

        return $batch->fresh();
    }

    /**
     * @param  list<string>  $timesheetIds
     * @return array{0: list<Timesheet>, 1: list<array{timesheet_id: string, recipient_email: ?string, reason: string}>}
     */
    private function resolveAndAuthorize(array $timesheetIds, User $actor): array
    {
        $found = Timesheet::query()->with('employee.user')->whereIn('id', $timesheetIds)->get()->keyBy('id');

        $valid = [];
        $skipped = [];

        foreach ($timesheetIds as $id) {
            /** @var Timesheet|null $timesheet */
            $timesheet = $found->get($id);

            if ($timesheet === null) {
                $skipped[] = ['timesheet_id' => $id, 'recipient_email' => null, 'reason' => 'ტაბელი ვერ მოიძებნა'];

                continue;
            }

            if ($timesheet->organization_id !== $actor->organization_id || ! $actor->can('send', $timesheet)) {
                // Cross-organization and permission-denied are deliberately
                // reported with the SAME generic reason text — distinguishing
                // them in a response the requesting user can read would leak
                // that a timesheet id from another organization exists at
                // all, which the ticket's own "სხვა კომპანიის/ორგანიზაციის
                // ID request-ში → უარი, გაჟონვის გარეშე" scenario forbids.
                $skipped[] = ['timesheet_id' => $id, 'recipient_email' => null, 'reason' => 'წვდომა უარყოფილია'];

                continue;
            }

            $valid[] = $timesheet;
        }

        return [$valid, $skipped];
    }

    /**
     * @param  list<Timesheet>  $timesheets
     * @return list<array{0: class-string, 1: TimesheetEmailDelivery}>
     */
    private function createPerEmployeeDeliveries(TimesheetEmailBatch $batch, array $timesheets, User $actor): array
    {
        $dispatchTargets = [];
        $skipped = $batch->skipped_details ?? [];

        foreach ($timesheets as $timesheet) {
            $recipientEmail = $timesheet->employee->user?->email;

            if ($recipientEmail === null) {
                // spec: "თანამშრომელს შეიძლება User ანგარიში არ ჰქონდეს...
                // არ გამოიგონო email" — never fabricate an address, skip
                // explicitly instead.
                $skipped[] = [
                    'timesheet_id' => $timesheet->id,
                    'recipient_email' => null,
                    'reason' => 'თანამშრომელს არ აქვს დაკავშირებული ანგარიშის ელფოსტა',
                ];

                continue;
            }

            $snapshot = TimesheetSnapshotResolver::resolve($timesheet, $actor);

            $employeeName = trim($timesheet->employee->first_name.' '.$timesheet->employee->last_name);

            $delivery = TimesheetEmailDelivery::query()->create([
                'batch_id' => $batch->id,
                'timesheet_id' => $timesheet->id,
                'timesheet_version_at_send' => $timesheet->version,
                'attachment_id' => $snapshot->id,
                'recipient_email' => $recipientEmail,
                'recipient_user_id' => $timesheet->employee->user_id,
                'subject' => "თქვენი ტაბელი — {$employeeName}",
                'status' => 'queued',
                'requested_by_user_id' => $actor->id,
                'created_at' => now(),
            ]);

            $dispatchTargets[] = [SendTimesheetEmailJob::class, $delivery];
        }

        $batch->update(['skipped_details' => $skipped === [] ? null : $skipped]);

        return $dispatchTargets;
    }

    /**
     * @param  list<Timesheet>  $timesheets
     * @return list<array{0: class-string, 1: TimesheetEmailDelivery}>
     */
    private function createBundledDelivery(
        TimesheetEmailBatch $batch,
        array $timesheets,
        ?string $recipientEmail,
        ?string $recipientUserId,
        User $actor,
    ): array {
        if ($timesheets === []) {
            return [];
        }

        $first = $timesheets[0];
        $firstSnapshot = TimesheetSnapshotResolver::resolve($first, $actor);

        $delivery = TimesheetEmailDelivery::query()->create([
            'batch_id' => $batch->id,
            // See the delivery-items migration's docblock: these three
            // columns reference only the FIRST bundled timesheet, for
            // display/reference parity with a plain single send — the
            // complete attached set is `items()` below.
            'timesheet_id' => $first->id,
            'timesheet_version_at_send' => $first->version,
            'attachment_id' => $firstSnapshot->id,
            'recipient_email' => $recipientEmail,
            'recipient_user_id' => $recipientUserId,
            'subject' => 'ტაბელები — '.count($timesheets).' დოკუმენტი',
            'status' => 'queued',
            'requested_by_user_id' => $actor->id,
            'created_at' => now(),
        ]);

        foreach ($timesheets as $timesheet) {
            $snapshot = $timesheet->is($first) ? $firstSnapshot : TimesheetSnapshotResolver::resolve($timesheet, $actor);

            TimesheetEmailDeliveryItem::query()->create([
                'delivery_id' => $delivery->id,
                'timesheet_id' => $timesheet->id,
                'timesheet_version_at_send' => $timesheet->version,
                'attachment_id' => $snapshot->id,
                'created_at' => now(),
            ]);
        }

        return [[SendBundledTimesheetEmailJob::class, $delivery]];
    }
}
