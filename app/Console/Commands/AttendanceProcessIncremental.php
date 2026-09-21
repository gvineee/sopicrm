<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Actions\ReconstructAttendanceSessionsAction;
use App\Domain\Attendance\Models\AttendanceIncrementalCheckpoint;
use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Auth\Actions\GetOrCreateSystemActorAction;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * QUEUE-01 (deferred remainder, docs/claude-overnight-progress.md's own
 * recorded next step): schedules real, incremental attendance
 * reconstruction so a raw event doesn't just sit unreconstructed forever
 * between whatever ad hoc manual triggers happen to run
 * `App\Http\Controllers\Attendance\AttendanceSessionController::reconstruct()`.
 *
 * Iterates every real organization (this is inherently a cross-tenant
 * system process, like `outbox:relay`), and within EACH one sets
 * `app.current_org_id` to that org's OWN real id — unlike `outbox:relay`,
 * this needs no cross-tenant RLS escape hatch, because every table this
 * command touches already has a standard single-tenant policy and this
 * command only ever reads/writes one concrete organization's own rows at a
 * time, never across two organizations in the same query.
 *
 * "Incremental" means: an employee is only re-processed if a raw event
 * newer than their own `AttendanceIncrementalCheckpoint.last_processed_at`
 * actually exists — an employee with no new events since last run does
 * nothing on this tick. An employee's own checkpoint is what bounds the
 * reconstruction window's start; a first-ever run (no checkpoint yet) looks
 * back a fixed, bounded window rather than the employee's entire history.
 */
class AttendanceProcessIncremental extends Command
{
    protected $signature = 'attendance:process-incremental {--lookback-days=3 : window to scan on an employee\'s very first run}';

    protected $description = 'Re-run attendance session reconstruction for employees with new raw events since their last checkpoint.';

    private const LOOKBACK_DAYS_DEFAULT = 3;

    public function handle(
        GetOrCreateSystemActorAction $getOrCreateSystemActor,
        ReconstructAttendanceSessionsAction $reconstruct,
    ): int {
        $previousOrganizationId = CurrentOrganization::id();
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        $totalProcessed = 0;

        try {
            $organizationIds = Organization::query()->pluck('id');

            foreach ($organizationIds as $organizationId) {
                $this->setOrganizationContext($organizationId, $isPgsql);

                $totalProcessed += $this->processOrganization($organizationId, $getOrCreateSystemActor, $reconstruct);
            }
        } finally {
            $this->setOrganizationContext($previousOrganizationId, $isPgsql);
        }

        $this->info("Reconstructed attendance for {$totalProcessed} employee(s) with new events.");

        return self::SUCCESS;
    }

    private function processOrganization(
        string $organizationId,
        GetOrCreateSystemActorAction $getOrCreateSystemActor,
        ReconstructAttendanceSessionsAction $reconstruct,
    ): int {
        $checkpointsByEmployee = AttendanceIncrementalCheckpoint::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->keyBy('employee_id');

        $employeeIds = CredentialAssignment::query()
            ->where('organization_id', $organizationId)
            ->distinct()
            ->pluck('employee_id');

        $processed = 0;
        $systemActor = null;

        foreach ($employeeIds as $employeeId) {
            $checkpoint = $checkpointsByEmployee->get($employeeId);
            $since = $checkpoint !== null
                ? $checkpoint->last_processed_at
                : Carbon::now()->subDays((int) $this->option('lookback-days') ?: self::LOOKBACK_DAYS_DEFAULT);

            $credentialIds = CredentialAssignment::query()
                ->where('organization_id', $organizationId)
                ->where('employee_id', $employeeId)
                ->pluck('credential_id');

            $hasNewEvents = RawAccessEvent::query()
                ->where('organization_id', $organizationId)
                ->whereIn('credential_id', $credentialIds)
                ->where('received_at', '>', $since)
                ->exists();

            if (! $hasNewEvents) {
                continue;
            }

            $systemActor ??= $getOrCreateSystemActor->execute($organizationId);
            $now = Carbon::now();

            /** @var Employee $employee */
            $employee = Employee::query()->where('id', (string) $employeeId)->firstOrFail();

            $reconstruct->handle(
                $employee,
                $since,
                $now,
                $systemActor,
            );

            AttendanceIncrementalCheckpoint::query()->updateOrCreate(
                ['organization_id' => $organizationId, 'employee_id' => $employeeId],
                ['last_processed_at' => $now],
            );

            $processed++;
        }

        return $processed;
    }

    private function setOrganizationContext(?string $organizationId, bool $isPgsql): void
    {
        CurrentOrganization::set($organizationId);

        if ($isPgsql) {
            DB::statement("select set_config('app.current_org_id', ?, false)", [$organizationId ?? '']);
        }
    }
}
