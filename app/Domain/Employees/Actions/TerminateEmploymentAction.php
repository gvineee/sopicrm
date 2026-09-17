<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Domain\Employees\DataTransferObjects\TerminationOutcome;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * spec section 5 hard rule: "დასაქმების დასრულება აუქმებს login-ს, გეგმავს
 * ბარათის გაუქმებას ყველა შესაბამის მოწყობილობაზე და აჩვენებს დაუბრუნებელ
 * ხელსაწყოებს. ისტორიული დასწრება და ფინანსური ჩანაწერები არ იშლება.
 * თანამშრომლის გამორთვა არ ნიშნავს დაკარგული ხელსაწყოს ღირებულების
 * ავტომატურ ჩამოჭრას."
 *
 * Three side effects, all inside one transaction:
 *  (a) the linked User (if any) is deactivated — not deleted, so historical
 *      audit/approval attribution stays intact;
 *  (b) every active CredentialAssignment for this employee gets a
 *      `revoke_credential` DeviceSyncCommand PER relevant device — this only
 *      SCHEDULES the revocation (desired state); the CredentialAssignment
 *      itself is NOT flipped to 'revoked' here, since spec section 6
 *      explicitly requires desired-state vs. device-acknowledged-state to
 *      stay visibly distinct (an offline device's pending revocation must
 *      never render as completed) — that transition belongs to the Devices
 *      module's own command-acknowledgement ingestion, not this Action;
 *  (c) unreturned CustodyTransactions are queried and returned (never
 *      mutated) so the caller can surface them — no automatic cost
 *      deduction is ever created here.
 *
 * "All relevant devices" for a credential assignment: this schema has no
 * direct assignment->device link (a credential's usable devices are implied
 * by its `site_scope`, per docs/data-model.md's own "site/access-group list"
 * note) — this Action resolves relevant devices as every device at the
 * sites named in `site_scope['site_ids']`, or every device in the
 * organization when `site_scope` is empty/null (company-wide credential).
 * Routine decision, logged in docs/decisions.md.
 */
class TerminateEmploymentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Employee $employee, string $endedOn, string $endReason, User $actor): TerminationOutcome
    {
        if ($employee->status === 'terminated') {
            throw new RuntimeException('თანამშრომელი უკვე დათხოვნილია.');
        }

        return DB::transaction(function () use ($employee, $endedOn, $endReason, $actor) {
            $before = $employee->only(['status']);

            $employee->status = 'terminated';
            $employee->save();

            $employment = $employee->employments()->where('status', 'active')->latest('started_at')->first();

            if ($employment !== null) {
                $employment->update([
                    'ended_at' => $endedOn,
                    'end_reason' => $endReason,
                    'status' => 'ended',
                ]);
            }

            $loginRevoked = false;

            if ($employee->user_id !== null) {
                $user = User::query()->find($employee->user_id);

                if ($user !== null && $user->is_active) {
                    $user->is_active = false;
                    $user->save();
                    $loginRevoked = true;
                }
            }

            $commandsScheduled = $this->scheduleCredentialRevocations($employee);

            $unreturned = $this->unreturnedCustodyTransactions($employee);

            $this->auditLogger->log(
                action: 'employees.employment.terminated',
                target: $employee,
                before: $before,
                after: ['status' => 'terminated', 'end_reason' => $endReason, 'ended_on' => $endedOn],
                reason: $endReason,
                actor: $actor,
            );

            return new TerminationOutcome($loginRevoked, $commandsScheduled, $unreturned);
        });
    }

    private function scheduleCredentialRevocations(Employee $employee): int
    {
        $assignments = CredentialAssignment::query()
            ->where('employee_id', $employee->id)
            ->active()
            ->get();

        $scheduled = 0;

        foreach ($assignments as $assignment) {
            $siteIds = $assignment->site_scope;

            $devices = Device::query()
                ->when(
                    $siteIds !== null && $siteIds !== [],
                    fn ($query) => $query->whereIn('site_id', $siteIds),
                )
                ->get();

            foreach ($devices as $device) {
                $idempotencyKey = "employee-termination:{$employee->id}:{$assignment->id}:{$device->id}";

                if (DeviceSyncCommand::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                    continue;
                }

                $nextVersion = 1 + (int) DeviceSyncCommand::query()
                    ->where('device_id', $device->id)
                    ->where('target_entity_type', $assignment->getMorphClass())
                    ->where('target_entity_id', $assignment->id)
                    ->max('command_version');

                DeviceSyncCommand::query()->create([
                    'device_id' => $device->id,
                    'command_type' => 'revoke_credential',
                    'payload' => [
                        'credential_id' => $assignment->credential_id,
                        'employee_id' => $employee->id,
                        'reason' => 'employment_terminated',
                    ],
                    'idempotency_key' => $idempotencyKey,
                    'target_entity_type' => $assignment->getMorphClass(),
                    'target_entity_id' => $assignment->id,
                    'command_version' => $nextVersion,
                    'status' => 'pending',
                ]);

                $scheduled++;
            }
        }

        return $scheduled;
    }

    /**
     * @return Collection<int, CustodyTransaction>
     */
    private function unreturnedCustodyTransactions(Employee $employee): Collection
    {
        return CustodyTransaction::query()
            ->where('receiving_employee_id', $employee->id)
            ->whereIn('status', ['issued', 'partially_returned', 'awaiting_receipt'])
            ->with(['lines' => fn ($query) => $query->whereColumn('returned_quantity', '<', 'quantity'), 'lines.asset'])
            ->get()
            ->filter(fn (CustodyTransaction $transaction) => $transaction->lines->isNotEmpty())
            ->values();
    }
}
