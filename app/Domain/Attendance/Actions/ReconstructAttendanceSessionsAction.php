<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\AttendanceSessionBreakDeduction;
use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Attendance\Support\BreakPolicyCalculator;
use App\Domain\Attendance\Support\ProjectAttributionResolver;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Domain\Employees\Models\Employee;
use App\Domain\Notifications\Support\NotificationCreator;
use App\Domain\Notifications\Support\NotificationType;
use App\Domain\Notifications\Support\UsersWithPermission;
use App\Domain\Timesheets\Actions\HandleLateArrivingEventAction;
use App\Domain\Timesheets\Support\TimesheetLockGuard;
use App\Domain\Timesheets\Support\WorkDateResolver;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-ATT-03..08: deterministic, re-runnable AttendanceSession reconstruction
 * from RawAccessEvents. Re-running against the same raw events for the same
 * employee/range always produces the same sessions — achieved by superseding
 * (never deleting/mutating) every non-superseded session touching the range
 * before rebuilding it fresh from the ordered raw event stream.
 *
 * Reuses App\Domain\Timesheets\Support\WorkDateResolver deliberately: the
 * "night shift attributes to its start date" rule is spec-identical between
 * Attendance and Timesheets (data-model.md), and it is a pure, dependency-free
 * static helper — importing it here does not create a reverse dependency on
 * any Timesheets business logic.
 *
 * Anomaly detection covers the types that only make sense at reconstruction
 * time (duplicate_in, unknown_out, missing_out, excessive_duration,
 * impossible_site_crossing, late_arriving_data, undirected_reader);
 * out_of_order_events, data_gap and clock_drift are detected earlier, at
 * ingestion time, by App\Domain\Devices\Actions\IngestRawAccessEventAction.
 *
 * `undirected_reader` is the one that explains an ABSENCE. A reader whose
 * `reader_role` is `unspecified` cannot open or close a session, because a
 * guessed direction is invented hours — but staying silent about it left an
 * employee with twenty-one real badge reads showing an empty attendance
 * record and no way to find out why. The absence now says what is missing and
 * which devices need configuring.
 *
 * `flagAnomaly()` deduplicates against any existing *unresolved* anomaly of
 * the same type for the same underlying trigger (the session, for
 * session-attached types; the specific raw event, for the two device-level
 * types that have no session) — a rerun of reconstruction over an
 * already-processed range never spams a second row for the same real
 * condition (ATT-01, reviewed 2026-09-21).
 *
 * Locked-period events (spec section 7 hard rule: "Locked პერიოდში
 * დაგვიანებული მოვლენა ქმნის adjustment request-ს; ისტორიულ ხელფასს ჩუმად არ
 * ცვლის") never reach the session state machine at all: for any event whose
 * work_date's Timesheet is already `locked` (per
 * App\Domain\Timesheets\Support\TimesheetLockGuard), that work_date's
 * existing sessions are left completely untouched (not even superseded), and
 * the event is routed to App\Domain\Timesheets\Actions\HandleLateArrivingEventAction
 * instead — which creates a flagged AttendanceAdjustment for a human to
 * review, never a silent rewrite of already-paid history.
 *
 * ATT-01 (docs/claude-platform-completion-2026-09-21.md, audit finding B3),
 * two real bugs fixed:
 *  - A denied swipe (`event_code === 'access_denied'`) is now excluded
 *    before it ever reaches the state machine — previously any event with a
 *    real `reader_direction_snapshot` opened/closed a session regardless of
 *    whether the door actually granted access, so somebody turned away at the
 *    gate could be paid for the day. Both producers normalize onto that one
 *    string: the simulator by its own convention, and the real BioStar read
 *    path through `App\Domain\Devices\Support\BiostarEventTaxonomy`.
 *  - Events were previously selected once per (employee, range) by finding
 *    every credential ever assigned to that employee whose validity window
 *    overlapped the range AT ALL, then pulling every one of that
 *    credential's events across the whole range — so if a physical card was
 *    reassigned from employee A to employee B partway through the range,
 *    BOTH employees' reconstructions could pick up the other's events for
 *    whichever days actually belonged to them. Each event is now
 *    individually re-checked against `CredentialAssignment::scopeActiveAt()`
 *    for that event's own `normalized_event_time_utc`, not the range as a
 *    whole.
 *
 * BIO-02: `orderedEventsFor()` also pulls in historical events whose card
 * went unrecognized at ingestion time (`credential_id = null`,
 * `unmatched_credential_ref` set) once a human has confirmed that reference
 * via App\Domain\Devices\Actions\ConfirmExternalIdentifierMappingAction —
 * resolved through App\Domain\Devices\Models\ExternalIdentifierMapping,
 * never by rewriting the immutable RawAccessEvent row itself.
 */
class ReconstructAttendanceSessionsAction
{
    public function __construct(
        private readonly TimesheetLockGuard $lockGuard,
        private readonly HandleLateArrivingEventAction $handleLateArrivingEvent,
    ) {}

    /**
     * @return list<AttendanceSession>
     */
    public function handle(Employee $employee, CarbonInterface $from, CarbonInterface $to, User $actor): array
    {
        // A `first_last` day is paired from its earliest read, so a window that
        // starts mid-day (the incremental checkpoint, typically) would rebuild
        // the day from whatever reads happen to fall after it and invent a
        // second, shorter session. Every run therefore starts at the local
        // midnight of its window's first day.
        $from = WorkDateResolver::startDateFor($from);

        return DB::transaction(function () use ($employee, $from, $to, $actor) {
            $runId = (string) Str::uuid();

            $events = $this->orderedEventsFor($employee, $from, $to);

            // spec section 7 hard rule: "Locked პერიოდში დაგვიანებული მოვლენა
            // ქმნის adjustment request-ს; ისტორიულ ხელფასს ჩუმად არ ცვლის."
            // An event whose work_date's timesheet is already locked is never
            // folded into a session rebuild — it's routed to a human review
            // queue instead, and that work_date's existing sessions are left
            // untouched entirely (not even superseded).
            $lockedWorkDates = [];
            $normalEvents = [];

            foreach ($events as $event) {
                $workDate = WorkDateResolver::startDateFor($event->normalized_event_time_utc);

                if ($this->lockGuard->isWorkDateLocked($employee->id, $workDate)) {
                    $lockedWorkDates[$workDate->toDateString()] = true;
                    $this->routeLockedPeriodEvent($employee, $event, $workDate, $actor);

                    continue;
                }

                $normalEvents[] = $event;
            }

            AttendanceSession::query()
                ->where('employee_id', $employee->id)
                ->where('status', '!=', 'superseded')
                ->whereBetween('clock_in_at', [$from, $to])
                // work_date is stored as a full datetime (e.g. "2026-09-21
                // 00:00:00"), so this must compare on the date part only —
                // a plain whereNotIn against Y-m-d strings never matches.
                ->when(
                    $lockedWorkDates !== [],
                    fn ($query) => $query->whereNotIn(DB::raw('date(work_date)'), array_keys($lockedWorkDates)),
                )
                ->update(['status' => 'superseded']);

            $this->checkUndirectedReaders($employee, $from, $to);

            $sessions = $this->reconstructFirstLastDays(
                $employee,
                array_values(array_filter($normalEvents, fn (RawAccessEvent $e): bool => $e->reader_direction_snapshot === 'first_last')),
                $runId,
            );
            $open = null;
            $previousEvent = null;

            foreach ($normalEvents as $event) {
                $direction = $event->reader_direction_snapshot;

                if ($direction !== 'in' && $direction !== 'out') {
                    continue;
                }

                $this->checkLateArriving($employee, $event);

                if ($direction === 'in') {
                    if ($open !== null) {
                        $this->flagAnomaly($employee->id, 'duplicate_in', $open, [
                            'existing_session_id' => $open->id,
                            'duplicate_raw_access_event_id' => $event->id,
                        ]);
                        $previousEvent = $event;

                        continue;
                    }

                    $open = $this->openSession($employee, $event, $runId);
                    $sessions[] = $open;

                    $this->checkImpossibleCrossing($employee, $previousEvent, $event, $open);
                } else {
                    if ($open === null) {
                        $this->flagAnomaly($employee->id, 'unknown_out', null, [
                            'raw_access_event_id' => $event->id,
                        ]);
                        $previousEvent = $event;

                        continue;
                    }

                    $this->closeSession($open, $event);
                    $open = null;
                }

                $previousEvent = $event;
            }

            if ($open !== null) {
                $this->flagAnomaly($employee->id, 'missing_out', $open, [
                    'clock_in_event_id' => $open->clock_in_event_id,
                ]);
            }

            return $sessions;
        });
    }

    /**
     * A badge the door REFUSED. Both producers normalize onto this one string:
     * the simulator by its own convention, and the real BioStar read path
     * through `App\Domain\Devices\Support\BiostarEventTaxonomy`, which maps the
     * live server's numeric families (VERIFY_FAIL_*, ACCESS_DENIED_* and the
     * rest) onto it. Anything else would sail past this filter and a refused
     * read would be counted as an arrival.
     */
    private const DENIED_EVENT_CODES = ['access_denied'];

    /**
     * A badge read the CRM cannot turn into a worked interval, because nobody
     * has said which side of the door the reader is on.
     *
     * Reconstruction has always skipped these, and must: a session built from
     * a guessed direction is somebody's invented hours. What it did not do was
     * SAY so — an employee with twenty-one real reads on the live install had
     * a completely empty attendance record and no explanation anywhere. One
     * unresolved anomaly per employee names the readers to configure, and
     * clears itself from the next reconstruction once they are.
     */
    private function checkUndirectedReaders(Employee $employee, CarbonInterface $from, CarbonInterface $to): void
    {
        // Only readers that are STILL undecided. A read taken before somebody
        // chose the reader's role keeps its `unspecified` snapshot (history
        // is not rewritten), but once the role is chosen there is nothing
        // left to configure, and an anomaly asking for it would be false.
        $undirected = $this->eventsFor($employee, $from, $to, ['unspecified'])
            ->filter(fn (RawAccessEvent $event): bool => $event->device->reader_role === 'unspecified')
            ->values();

        if ($undirected->isEmpty()) {
            return;
        }

        $devices = $undirected
            ->map(fn (RawAccessEvent $event): array => [
                'device_id' => $event->device_id,
                'serial_number' => $event->device->serial_number,
                'name' => $event->device->name,
            ])
            ->unique('device_id')
            ->values()
            ->all();

        $this->flagAnomaly($employee->id, 'undirected_reader', null, [
            // The specific event is what `flagAnomaly` dedupes on when there
            // is no session, so the oldest one is used deliberately: it keeps
            // the anomaly attached to the same trigger across reruns instead
            // of opening a fresh row every time a newer swipe arrives.
            'raw_access_event_id' => $undirected->first()->id,
            'event_count' => $undirected->count(),
            'devices' => $devices,
        ]);
    }

    /**
     * @return Collection<int, RawAccessEvent>
     */
    private function orderedEventsFor(Employee $employee, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->eventsFor($employee, $from, $to, ['in', 'out', 'first_last']);
    }

    /**
     * The owner's rule for an attendance-only reader (`reader_role =
     * first_last`): „დღის პირველი დაფიქსირება იქნება მოსვლა, დღის ბოლო
     * დაფიქსირება იქნება წასვლა". Reads in between are real and stay in the
     * raw log, but do not split the day.
     *
     * A day with a single read has an arrival and no departure. While that day
     * is still running that is simply somebody at work, so the session is left
     * open without complaint; once the day is over it is a `missing_out`, the
     * same as an unclosed IN on a directed reader — never a guessed end time.
     *
     * @param  list<RawAccessEvent>  $reads  ordered by time, locked dates already removed
     * @return list<AttendanceSession>
     */
    private function reconstructFirstLastDays(Employee $employee, array $reads, string $runId): array
    {
        $today = WorkDateResolver::startDateFor(now())->toDateString();
        $sessions = [];

        $byDay = collect($reads)->groupBy(
            fn (RawAccessEvent $read): string => WorkDateResolver::startDateFor($read->normalized_event_time_utc)->toDateString(),
        );

        foreach ($byDay as $workDate => $dayReads) {
            /** @var RawAccessEvent $first */
            $first = $dayReads->first();
            /** @var RawAccessEvent $last */
            $last = $dayReads->last();

            $this->checkLateArriving($employee, $first);
            $session = $this->openSession($employee, $first, $runId);
            $sessions[] = $session;

            if ($last->isNot($first)) {
                $this->checkLateArriving($employee, $last);
                $this->closeSession($session, $last);

                continue;
            }

            if ($workDate < $today) {
                $this->flagAnomaly($employee->id, 'missing_out', $session, [
                    'clock_in_event_id' => $session->clock_in_event_id,
                ]);
            }
        }

        return $sessions;
    }

    /**
     * @param  list<string>  $directions
     * @return Collection<int, RawAccessEvent>
     */
    private function eventsFor(Employee $employee, CarbonInterface $from, CarbonInterface $to, array $directions): Collection
    {
        $credentialIds = CredentialAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('valid_from', '<=', $to)
            ->where(function ($query) use ($from): void {
                $query->whereNull('valid_to')->orWhere('valid_to', '>=', $from);
            })
            ->pluck('credential_id');

        if ($credentialIds->isEmpty()) {
            return collect();
        }

        // BIO-02: a raw event ingested before its card was recognized keeps
        // `credential_id = null` forever (RawAccessEvent is immutable) —
        // `unmatched_credential_ref` is its only stable link back to a card.
        // Once App\Domain\Devices\Actions\ConfirmExternalIdentifierMappingAction
        // confirms that reference belongs to one of this employee's
        // credentials, those old rows must be pulled in here too, or
        // "reprocessing" after confirmation would have nothing to reprocess.
        $confirmedRefsByCredentialId = ExternalIdentifierMapping::query()
            ->where('external_type', 'card')
            ->where('status', 'confirmed')
            ->whereIn('target_id', $credentialIds)
            ->pluck('target_id', 'external_identifier');

        return RawAccessEvent::query()
            // Audit A04: „Simulator-ის შედეგები არ უნდა მონაწილეობდეს რეალურ
            // ტაბელსა და ხელფასში." This is the one place that has to enforce
            // it, because everything downstream reads sessions rather than raw
            // events: sessions become TimesheetLines
            // (App\Domain\Timesheets\Actions\BuildTimesheetLinesForSessionAction)
            // and those become money (App\Domain\Payroll\Actions\CalculatePayRunAction).
            // Before this, two clicks of the simulator's "generate test event"
            // button produced a real paid session within five minutes, with
            // nothing at any later stage able to tell it from a real badge read.
            ->excludingSimulated()
            ->where(function ($query) use ($credentialIds, $confirmedRefsByCredentialId): void {
                $query->whereIn('credential_id', $credentialIds);

                if ($confirmedRefsByCredentialId->isNotEmpty()) {
                    $query->orWhereIn('unmatched_credential_ref', $confirmedRefsByCredentialId->keys());
                }
            })
            ->whereBetween('normalized_event_time_utc', [$from, $to])
            ->whereIn('reader_direction_snapshot', $directions)
            ->whereNotIn('event_code', self::DENIED_EVENT_CODES)
            ->with('device')
            ->orderBy('normalized_event_time_utc')
            ->orderBy('native_event_id')
            ->get()
            // The query above only proves the credential was assigned to
            // this employee SOMEWHERE inside [from, to] — a card reassigned
            // from employee A to employee B partway through that range would
            // otherwise let both reconstructions claim the same events. Each
            // event is re-checked against who actually held the card at that
            // event's own instant. A once-unmatched row resolves its
            // effective credential via the confirmed mapping instead of its
            // own (permanently null) `credential_id` column.
            ->filter(fn (RawAccessEvent $event): bool => $this->credentialBelongedToEmployeeAt(
                $employee->id,
                $event->credential_id ?? $confirmedRefsByCredentialId->get($event->unmatched_credential_ref),
                $event->normalized_event_time_utc,
            ))
            ->values();
    }

    private function credentialBelongedToEmployeeAt(string $employeeId, ?string $credentialId, CarbonInterface $at): bool
    {
        if ($credentialId === null) {
            return false;
        }

        return CredentialAssignment::query()
            ->where('credential_id', $credentialId)
            ->where('employee_id', $employeeId)
            ->activeAt(Carbon::instance($at))
            ->exists();
    }

    private function openSession(Employee $employee, RawAccessEvent $event, string $runId): AttendanceSession
    {
        $device = $event->device;
        $workDate = WorkDateResolver::startDateFor($event->normalized_event_time_utc);

        return AttendanceSession::create([
            'employee_id' => $employee->id,
            'site_id' => $device->site_id,
            'project_id' => ProjectAttributionResolver::resolve($employee->id, $device->site_id, $workDate),
            'clock_in_event_id' => $event->id,
            'clock_in_at' => $event->normalized_event_time_utc,
            'work_date' => $workDate->toDateString(),
            'reconstruction_run_id' => $runId,
            'status' => 'open',
        ]);
    }

    private function closeSession(AttendanceSession $session, RawAccessEvent $event): void
    {
        $clockIn = Carbon::instance($session->clock_in_at);
        $clockOut = Carbon::instance($event->normalized_event_time_utc);
        $rawMinutes = BreakPolicyCalculator::exactMinutes($clockIn, $clockOut);

        $shiftTemplate = $this->resolveShiftTemplate($session->employee_id, $session->work_date);
        $deductedMinutes = 0;

        if ($shiftTemplate !== null) {
            foreach (BreakPolicyCalculator::deductions($shiftTemplate->break_policy, $clockIn, $clockOut) as $deduction) {
                AttendanceSessionBreakDeduction::query()->firstOrCreate(
                    [
                        'attendance_session_id' => $session->id,
                        'shift_template_id' => $shiftTemplate->id,
                        'break_window_key' => $deduction['key'],
                    ],
                    ['deducted_minutes' => $deduction['minutes']],
                );
                $deductedMinutes += $deduction['minutes'];
            }
        }

        $payableMinutes = max(0, $rawMinutes - $deductedMinutes);

        $session->update([
            'clock_out_event_id' => $event->id,
            'clock_out_at' => $event->normalized_event_time_utc,
            'raw_duration_minutes' => $rawMinutes,
            'payable_minutes' => $payableMinutes,
            'status' => 'closed',
        ]);

        $threshold = (int) config('attendance.excessive_duration_minutes', 960);
        if ($rawMinutes > $threshold) {
            $this->flagAnomaly($session->employee_id, 'excessive_duration', $session, [
                'raw_duration_minutes' => $rawMinutes,
                'threshold_minutes' => $threshold,
            ]);
        }
    }

    private function resolveShiftTemplate(string $employeeId, mixed $workDate): ?ShiftTemplate
    {
        $date = $workDate instanceof CarbonInterface ? $workDate->toDateString() : (string) $workDate;

        $assignment = ShiftAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->with('shiftTemplate')
            ->orderByDesc('effective_from')
            ->first();

        return $assignment?->shiftTemplate;
    }

    private function checkImpossibleCrossing(
        Employee $employee,
        ?RawAccessEvent $previousEvent,
        RawAccessEvent $event,
        AttendanceSession $newSession,
    ): void {
        if ($previousEvent === null || $previousEvent->device->site_id === $event->device->site_id) {
            return;
        }

        $gapMinutes = BreakPolicyCalculator::exactMinutes(
            Carbon::instance($previousEvent->normalized_event_time_utc),
            Carbon::instance($event->normalized_event_time_utc),
        );

        $minCrossingMinutes = (int) config('attendance.min_site_crossing_minutes', 30);

        if ($gapMinutes < $minCrossingMinutes) {
            $this->flagAnomaly($employee->id, 'impossible_site_crossing', $newSession, [
                'previous_raw_access_event_id' => $previousEvent->id,
                'previous_site_id' => $previousEvent->device->site_id,
                'new_site_id' => $event->device->site_id,
                'gap_minutes' => $gapMinutes,
                'threshold_minutes' => $minCrossingMinutes,
            ]);
        }
    }

    private function checkLateArriving(Employee $employee, RawAccessEvent $event): void
    {
        $lagMinutes = BreakPolicyCalculator::exactMinutes(
            Carbon::instance($event->normalized_event_time_utc),
            Carbon::instance($event->received_at),
        );

        $threshold = (int) config('attendance.late_arrival_threshold_minutes', 60);

        if ($lagMinutes > $threshold) {
            $this->flagAnomaly($employee->id, 'late_arriving_data', null, [
                'raw_access_event_id' => $event->id,
                'lag_minutes' => $lagMinutes,
                'threshold_minutes' => $threshold,
            ]);
        }
    }

    /**
     * Idempotency guard: one flagged AttendanceAdjustment per (employee,
     * work_date) is enough signal for a human reviewer — a rerun of
     * reconstruction over the same already-locked range must not spam a new
     * pending row per event.
     */
    private function routeLockedPeriodEvent(Employee $employee, RawAccessEvent $event, CarbonInterface $workDate, User $actor): void
    {
        $alreadyFlagged = AttendanceAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', $workDate->toDateString())
            ->where('for_locked_period', true)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($alreadyFlagged) {
            return;
        }

        $this->handleLateArrivingEvent->handle(
            $employee,
            $event->normalized_event_time_utc,
            $actor->id,
            $event->device->site_id ?? null,
            "attendance reconstruction pass, raw_access_event {$event->id}",
        );
    }

    /**
     * ATT-01: deduplicates against any existing *unresolved* anomaly for the
     * same real-world trigger, so re-running reconstruction over an
     * already-processed range doesn't spam a new row every time. Session-
     * attached types (everything except `unknown_out`/`late_arriving_data`)
     * dedupe on (employee, type, session) — a session is itself the stable,
     * versioned artifact reconstruction never duplicates, so at most one
     * unresolved anomaly of a given type ever needs to exist per session.
     * The types with no session (`unknown_out`, `late_arriving_data`,
     * `undirected_reader`) dedupe on the specific `raw_access_event_id` that
     * triggered them instead, since that's the only stable identity available.
     *
     * @param  array<string, mixed>  $details
     */
    private function flagAnomaly(string $employeeId, string $type, ?AttendanceSession $session, array $details): void
    {
        $duplicateQuery = AttendanceAnomaly::query()
            ->where('employee_id', $employeeId)
            ->where('anomaly_type', $type)
            ->whereNull('resolved_at');

        if ($session !== null) {
            $duplicateQuery->where('attendance_session_id', $session->id);
        } elseif (isset($details['raw_access_event_id'])) {
            $duplicateQuery->where('details->raw_access_event_id', $details['raw_access_event_id']);
        } else {
            // No stable natural key available for this anomaly — every
            // current call site passes either a session or a
            // raw_access_event_id, so this branch is defensive only.
            return;
        }

        if ($duplicateQuery->exists()) {
            return;
        }

        $anomaly = AttendanceAnomaly::create([
            'employee_id' => $employeeId,
            'attendance_session_id' => $session?->id,
            'anomaly_type' => $type,
            'detected_at' => now(),
            'details' => $details,
        ]);

        $this->notifyAnomaly($anomaly);
    }

    /**
     * NOTIFY-01: `timesheet_exception` — a fresh (not deduplicated)
     * attendance anomaly feeds directly into whether a Timesheet line for
     * this employee's period ends up flagged, so this is the closest real
     * hook to the ticket's named type. Broadcasts to every user in the
     * organization who currently holds `attendance.anomalies.view` (finance/
     * hr/owner, per database/seeders/modules/AttendancePermissionsSeeder.php)
     * rather than a single "manager" — this codebase has no reliable
     * employee-to-manager User link today (Employee::supervisor() points at
     * another Employee, which may itself have no linked User account).
     */
    private function notifyAnomaly(AttendanceAnomaly $anomaly): void
    {
        $employee = Employee::query()->find($anomaly->employee_id);

        if ($employee === null) {
            return;
        }

        foreach (UsersWithPermission::inOrganization($employee->organization_id, 'attendance.anomalies.view') as $recipient) {
            NotificationCreator::create(
                recipient: $recipient,
                type: NotificationType::TIMESHEET_EXCEPTION,
                title: 'დასწრების გამონაკლისი',
                message: "აღმოჩენილია გამონაკლისი ({$anomaly->anomaly_type}) თანამშრომლისთვის: {$employee->first_name} {$employee->last_name}",
                dedupKey: "timesheet_exception:{$anomaly->id}:{$recipient->id}",
                deepLink: '/attendance/anomalies',
            );
        }
    }
}
