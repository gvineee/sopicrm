<?php

use App\Domain\Attendance\Actions\ReconstructAttendanceSessionsAction;
use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Support\Carbon;

pest()->group('attendance');

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);

    $this->actor = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $this->device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
    ]);
    $this->employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $credential = Credential::factory()->create(['organization_id' => $this->organization->id]);
    CredentialAssignment::factory()->create([
        'organization_id' => $this->organization->id,
        'credential_id' => $credential->id,
        'employee_id' => $this->employee->id,
        'valid_from' => now()->subYear(),
    ]);
    $this->credential = $credential;
});

test('09:00-18:00 with a 60 minute fixed break yields 480 payable minutes (spec section 23)', function () {
    $template = ShiftTemplate::factory()->create([
        'organization_id' => $this->organization->id,
        'break_policy' => ['type' => 'fixed', 'minutes' => 60],
    ]);
    ShiftAssignment::factory()->create([
        'organization_id' => $this->organization->id,
        'employee_id' => $this->employee->id,
        'shift_template_id' => $template->id,
        'effective_from' => now()->subMonth(),
    ]);

    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');
    $clockIn = $day->copy()->setTime(9, 0);
    $clockOut = $day->copy()->setTime(18, 0);

    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'in',
        'normalized_event_time_utc' => $clockIn,
        'received_at' => $clockIn,
    ]);
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 2,
        'reader_direction_snapshot' => 'out',
        'normalized_event_time_utc' => $clockOut,
        'received_at' => $clockOut,
    ]);

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        $day->copy()->startOfDay(),
        $day->copy()->endOfDay(),
        $this->actor,
    );

    expect($sessions)->toHaveCount(1);
    $session = $sessions[0]->fresh();
    expect($session->status)->toBe('closed')
        ->and($session->raw_duration_minutes)->toBe(540)
        ->and($session->payable_minutes)->toBe(480);
});

test('a missing clock-out never auto-pays a full day and raises a missing_out anomaly', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');
    $clockIn = $day->copy()->setTime(9, 0);

    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'in',
        'normalized_event_time_utc' => $clockIn,
        'received_at' => $clockIn,
    ]);

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        $day->copy()->startOfDay(),
        $day->copy()->endOfDay(),
        $this->actor,
    );

    expect($sessions)->toHaveCount(1);
    $session = $sessions[0]->fresh();

    expect($session->status)->toBe('open')
        ->and($session->payable_minutes)->toBeNull()
        ->and(AttendanceAnomaly::query()
            ->where('attendance_session_id', $session->id)
            ->where('anomaly_type', 'missing_out')
            ->exists())->toBeTrue();
});

test('a duplicate clock-in is flagged and does not open a second overlapping session', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');
    $firstIn = $day->copy()->setTime(9, 0);
    $secondIn = $day->copy()->setTime(9, 30);

    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'in',
        'normalized_event_time_utc' => $firstIn,
        'received_at' => $firstIn,
    ]);
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 2,
        'reader_direction_snapshot' => 'in',
        'normalized_event_time_utc' => $secondIn,
        'received_at' => $secondIn,
    ]);

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        $day->copy()->startOfDay(),
        $day->copy()->endOfDay(),
        $this->actor,
    );

    expect($sessions)->toHaveCount(1)
        ->and(AttendanceSession::query()->where('status', '!=', 'superseded')->count())->toBe(1)
        ->and(AttendanceAnomaly::query()->where('anomaly_type', 'duplicate_in')->exists())->toBeTrue();
});

test('an out event with no open session is flagged unknown_out without creating a session', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');
    $clockOut = $day->copy()->setTime(18, 0);

    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'out',
        'normalized_event_time_utc' => $clockOut,
        'received_at' => $clockOut,
    ]);

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        $day->copy()->startOfDay(),
        $day->copy()->endOfDay(),
        $this->actor,
    );

    expect($sessions)->toHaveCount(0)
        ->and(AttendanceAnomaly::query()->where('anomaly_type', 'unknown_out')->exists())->toBeTrue();
});

test('reconstruction is idempotent: rerunning the same range supersedes the old session and rebuilds one fresh session', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');
    $clockIn = $day->copy()->setTime(9, 0);
    $clockOut = $day->copy()->setTime(17, 0);

    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'in',
        'normalized_event_time_utc' => $clockIn,
        'received_at' => $clockIn,
    ]);
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 2,
        'reader_direction_snapshot' => 'out',
        'normalized_event_time_utc' => $clockOut,
        'received_at' => $clockOut,
    ]);

    $action = app(ReconstructAttendanceSessionsAction::class);
    $first = $action->handle($this->employee, $day->copy()->startOfDay(), $day->copy()->endOfDay(), $this->actor);
    $second = $action->handle($this->employee, $day->copy()->startOfDay(), $day->copy()->endOfDay(), $this->actor);

    expect($first[0]->fresh()->status)->toBe('superseded')
        ->and($second)->toHaveCount(1)
        ->and($second[0]->fresh()->status)->toBe('closed')
        ->and($second[0]->fresh()->payable_minutes)->toBe($first[0]->fresh()->payable_minutes ?? 480);
});

test('an event for an already-locked work date never touches the session and instead raises a flagged adjustment', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');
    $clockIn = $day->copy()->setTime(9, 0);
    $clockOut = $day->copy()->setTime(17, 0);

    $existingSession = AttendanceSession::factory()->create([
        'organization_id' => $this->organization->id,
        'employee_id' => $this->employee->id,
        'site_id' => $this->site->id,
        'clock_in_at' => $clockIn,
        'clock_out_at' => $clockOut,
        'work_date' => $day->toDateString(),
        'raw_duration_minutes' => 480,
        'payable_minutes' => 480,
        'status' => 'closed',
    ]);

    $payPeriod = PayPeriod::factory()->create([
        'organization_id' => $this->organization->id,
        'starts_on' => $day->copy()->startOfMonth(),
        'ends_on' => $day->copy()->endOfMonth(),
    ]);
    Timesheet::factory()->create([
        'organization_id' => $this->organization->id,
        'employee_id' => $this->employee->id,
        'pay_period_id' => $payPeriod->id,
        'status' => 'locked',
    ]);

    $lateEvent = RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 99,
        'reader_direction_snapshot' => 'out',
        'normalized_event_time_utc' => $day->copy()->setTime(19, 0),
        'received_at' => now(),
    ]);

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        $day->copy()->startOfDay(),
        $day->copy()->endOfDay(),
        $this->actor,
    );

    expect($sessions)->toHaveCount(0)
        ->and($existingSession->fresh()->status)->toBe('closed')
        ->and(AttendanceAdjustment::query()
            ->where('employee_id', $this->employee->id)
            ->where('for_locked_period', true)
            ->where('status', 'pending')
            ->exists())->toBeTrue();
});

test('ATT-01: a denied swipe never opens a session', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');

    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'in',
        'event_code' => 'access_denied',
        'normalized_event_time_utc' => $day->copy()->setTime(9, 0),
        'received_at' => $day->copy()->setTime(9, 0),
    ]);

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        $day->copy()->startOfDay(),
        $day->copy()->endOfDay(),
        $this->actor,
    );

    expect($sessions)->toHaveCount(0)
        ->and(AttendanceSession::query()->count())->toBe(0);
});

test('ATT-01: reassigning a card mid-day attributes each event to whoever actually held it at that instant', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');
    $noon = $day->copy()->setTime(12, 0);

    $employeeB = Employee::factory()->create(['organization_id' => $this->organization->id]);

    // The shared physical card: employee A held it until noon, then it was
    // reissued to employee B for the rest of the day.
    $this->credential->assignments()->update(['status' => 'superseded', 'valid_to' => $noon]);
    CredentialAssignment::factory()->create([
        'organization_id' => $this->organization->id,
        'credential_id' => $this->credential->id,
        'employee_id' => $employeeB->id,
        'valid_from' => $noon,
        'status' => 'active',
    ]);

    // Employee A's real shift, entirely before the reassignment.
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'in',
        'normalized_event_time_utc' => $day->copy()->setTime(8, 0),
        'received_at' => $day->copy()->setTime(8, 0),
    ]);
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 2,
        'reader_direction_snapshot' => 'out',
        'normalized_event_time_utc' => $day->copy()->setTime(11, 0),
        'received_at' => $day->copy()->setTime(11, 0),
    ]);

    // Employee B's real shift, entirely after the reassignment, using the
    // SAME physical card/device.
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 3,
        'reader_direction_snapshot' => 'in',
        'normalized_event_time_utc' => $day->copy()->setTime(13, 0),
        'received_at' => $day->copy()->setTime(13, 0),
    ]);
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 4,
        'reader_direction_snapshot' => 'out',
        'normalized_event_time_utc' => $day->copy()->setTime(17, 0),
        'received_at' => $day->copy()->setTime(17, 0),
    ]);

    $sessionsA = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee, $day->copy()->startOfDay(), $day->copy()->endOfDay(), $this->actor,
    );
    CurrentOrganization::set($this->organization->id);
    $sessionsB = app(ReconstructAttendanceSessionsAction::class)->handle(
        $employeeB, $day->copy()->startOfDay(), $day->copy()->endOfDay(), $this->actor,
    );

    expect($sessionsA)->toHaveCount(1)
        ->and($sessionsA[0]->fresh()->clock_in_at->format('H:i'))->toBe('08:00')
        ->and($sessionsA[0]->fresh()->clock_out_at->format('H:i'))->toBe('11:00')
        ->and($sessionsB)->toHaveCount(1)
        ->and($sessionsB[0]->fresh()->clock_in_at->format('H:i'))->toBe('13:00')
        ->and($sessionsB[0]->fresh()->clock_out_at->format('H:i'))->toBe('17:00');
});

test('ATT-01: an overnight shift is attributed to the shift\'s start date, not the clock-out date', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');

    // WorkDateResolver converts to site-local time (Asia/Tbilisi, UTC+4)
    // before taking the calendar date — these UTC instants are chosen so
    // clock-in is still 22:00 *local* on $day and clock-out is 06:00 *local*
    // the next day, a genuine local-midnight-crossing shift.
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'in',
        'normalized_event_time_utc' => $day->copy()->setTime(18, 0),
        'received_at' => $day->copy()->setTime(18, 0),
    ]);
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 2,
        'reader_direction_snapshot' => 'out',
        'normalized_event_time_utc' => $day->copy()->addDay()->setTime(2, 0),
        'received_at' => $day->copy()->addDay()->setTime(2, 0),
    ]);

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        $day->copy()->startOfDay(),
        $day->copy()->addDay()->endOfDay(),
        $this->actor,
    );

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->fresh()->work_date->toDateString())->toBe($day->toDateString())
        ->and($sessions[0]->fresh()->status)->toBe('closed')
        ->and($sessions[0]->fresh()->raw_duration_minutes)->toBe(480);
});

test('ATT-01: rerunning reconstruction over the same unresolved problem never duplicates the anomaly row', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');

    // A lone OUT event with no prior IN — triggers unknown_out every pass,
    // since there is nothing to attach a session-based dedup key to.
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'out',
        'normalized_event_time_utc' => $day->copy()->setTime(18, 0),
        'received_at' => $day->copy()->setTime(18, 0),
    ]);

    $action = app(ReconstructAttendanceSessionsAction::class);
    $action->handle($this->employee, $day->copy()->startOfDay(), $day->copy()->endOfDay(), $this->actor);
    CurrentOrganization::set($this->organization->id);
    $action->handle($this->employee, $day->copy()->startOfDay(), $day->copy()->endOfDay(), $this->actor);
    CurrentOrganization::set($this->organization->id);

    expect(AttendanceAnomaly::query()->where('anomaly_type', 'unknown_out')->count())->toBe(1);
});

test('badge reads from a reader with no direction produce no session, and say so', function () {
    // The live BioStar install exactly: both doors have `exit_device: NONE`,
    // so nobody has told the CRM which side of the door this reader is on.
    $this->device->update(['reader_role' => 'unspecified', 'name' => 'XPass 2 544452272 შემოსასვლელი']);

    $day = Carbon::parse('2026-09-20 00:00:00', 'UTC');

    foreach ([9, 12, 17] as $index => $hour) {
        RawAccessEvent::factory()->create([
            'organization_id' => $this->organization->id,
            'device_id' => $this->device->id,
            'credential_id' => $this->credential->id,
            'native_event_id' => $index + 1,
            'reader_direction_snapshot' => 'unspecified',
            'event_code' => 'access_granted',
            'normalized_event_time_utc' => $day->copy()->setTime($hour, 0),
            'received_at' => $day->copy()->setTime($hour, 0),
        ]);
    }

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        $day->copy()->startOfDay(),
        $day->copy()->endOfDay(),
        $this->actor,
    );

    // No hours are invented from a guessed direction — that part was always
    // right.
    expect($sessions)->toBeEmpty();

    // What was missing: anything at all telling the operator why an employee
    // with three real badge reads has an empty day.
    $anomaly = AttendanceAnomaly::query()
        ->where('employee_id', $this->employee->id)
        ->where('anomaly_type', 'undirected_reader')
        ->sole();

    expect($anomaly->details['event_count'])->toBe(3)
        ->and($anomaly->details['devices'])->toHaveCount(1)
        ->and($anomaly->details['devices'][0]['serial_number'])->toBe($this->device->serial_number);
});

test('the undirected-reader anomaly is raised once, not once per swipe or per rerun', function () {
    $this->device->update(['reader_role' => 'unspecified']);
    $day = Carbon::parse('2026-09-20 00:00:00', 'UTC');

    foreach (range(1, 4) as $index) {
        RawAccessEvent::factory()->create([
            'organization_id' => $this->organization->id,
            'device_id' => $this->device->id,
            'credential_id' => $this->credential->id,
            'native_event_id' => $index,
            'reader_direction_snapshot' => 'unspecified',
            'event_code' => 'access_granted',
            'normalized_event_time_utc' => $day->copy()->setTime(8 + $index, 0),
            'received_at' => $day->copy()->setTime(8 + $index, 0),
        ]);
    }

    $reconstruct = app(ReconstructAttendanceSessionsAction::class);
    $reconstruct->handle($this->employee, $day->copy()->startOfDay(), $day->copy()->endOfDay(), $this->actor);
    $reconstruct->handle($this->employee, $day->copy()->startOfDay(), $day->copy()->endOfDay(), $this->actor);

    expect(AttendanceAnomaly::query()->where('anomaly_type', 'undirected_reader')->count())->toBe(1);
});

test('once the readers are configured the reads pair normally and nothing new is flagged', function () {
    $exit = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
        'reader_role' => 'out',
    ]);
    $this->device->update(['reader_role' => 'in']);

    $day = Carbon::parse('2026-09-20 00:00:00', 'UTC');

    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 1,
        'reader_direction_snapshot' => 'in',
        'event_code' => 'access_granted',
        'normalized_event_time_utc' => $day->copy()->setTime(9, 0),
        'received_at' => $day->copy()->setTime(9, 0),
    ]);
    RawAccessEvent::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $exit->id,
        'credential_id' => $this->credential->id,
        'native_event_id' => 2,
        'reader_direction_snapshot' => 'out',
        'event_code' => 'access_granted',
        'normalized_event_time_utc' => $day->copy()->setTime(18, 0),
        'received_at' => $day->copy()->setTime(18, 0),
    ]);

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        $day->copy()->startOfDay(),
        $day->copy()->endOfDay(),
        $this->actor,
    );

    // The companion to the two tests above: the anomaly proves an absence, so
    // this proves the mechanism it stands in for still works once the missing
    // configuration is supplied.
    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->status)->toBe('closed')
        ->and($sessions[0]->raw_duration_minutes)->toBe(540)
        ->and(AttendanceAnomaly::query()->where('anomaly_type', 'undirected_reader')->exists())->toBeFalse();
});

/*
 * The owner's rule for the „აღრიცხვა" reader (2026-09-25): it exists only to
 * record attendance — the first read of a day is the arrival, the last read is
 * the departure. Tbilisi is UTC+4, so 05:00 UTC is 09:00 local.
 */
function firstLastRead(object $test, int $nativeId, Carbon $at, ?Device $device = null): RawAccessEvent
{
    return RawAccessEvent::factory()->create([
        'organization_id' => $test->organization->id,
        'device_id' => ($device ?? $test->device)->id,
        'credential_id' => $test->credential->id,
        'native_event_id' => $nativeId,
        'reader_direction_snapshot' => ($device ?? $test->device)->reader_role,
        'event_code' => 'access_granted',
        'normalized_event_time_utc' => $at,
        'received_at' => $at,
    ]);
}

test('an attendance-only reader pairs the first read of the day with the last, ignoring those between', function () {
    $this->device->update(['reader_role' => 'first_last']);
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');

    firstLastRead($this, 1, $day->copy()->setTime(5, 0));
    firstLastRead($this, 2, $day->copy()->setTime(9, 0));
    firstLastRead($this, 3, $day->copy()->setTime(10, 0));
    firstLastRead($this, 4, $day->copy()->setTime(14, 0));

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle($this->employee, $day, $day->copy()->endOfDay(), $this->actor);

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->fresh()->status)->toBe('closed')
        ->and($sessions[0]->fresh()->raw_duration_minutes)->toBe(540)
        ->and(AttendanceAnomaly::query()->count())->toBe(0);
});

test('each local day on an attendance-only reader is its own session', function () {
    $this->device->update(['reader_role' => 'first_last']);
    $monday = Carbon::parse('2026-09-21 00:00:00', 'UTC');
    $tuesday = Carbon::parse('2026-09-22 00:00:00', 'UTC');

    firstLastRead($this, 1, $monday->copy()->setTime(5, 0));
    firstLastRead($this, 2, $monday->copy()->setTime(13, 0));
    firstLastRead($this, 3, $tuesday->copy()->setTime(6, 0));
    firstLastRead($this, 4, $tuesday->copy()->setTime(14, 0));

    app(ReconstructAttendanceSessionsAction::class)->handle($this->employee, $monday, $tuesday->copy()->endOfDay(), $this->actor);

    $sessions = AttendanceSession::query()->where('status', 'closed')->orderBy('clock_in_at')->get();

    expect($sessions)->toHaveCount(2)
        ->and($sessions->pluck('raw_duration_minutes')->all())->toBe([480, 480]);
});

test('a lone read is an open day while the day runs, and a missing exit once it is over', function () {
    $this->device->update(['reader_role' => 'first_last']);
    Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00', 'UTC'));

    firstLastRead($this, 1, Carbon::parse('2026-09-21 05:00:00', 'UTC'));
    firstLastRead($this, 2, Carbon::parse('2026-09-22 05:00:00', 'UTC'));

    app(ReconstructAttendanceSessionsAction::class)->handle(
        $this->employee,
        Carbon::parse('2026-09-21 00:00:00', 'UTC'),
        now(),
        $this->actor,
    );

    $sessions = AttendanceSession::query()->where('status', '!=', 'superseded')->orderBy('clock_in_at')->get();
    $missing = AttendanceAnomaly::query()->where('anomaly_type', 'missing_out')->get();

    expect($sessions->pluck('status')->all())->toBe(['open', 'open'])
        ->and($missing)->toHaveCount(1)
        ->and($missing[0]->attendance_session_id)->toBe($sessions[0]->id);

    Carbon::setTestNow();
});

test('a window starting mid-day still pairs from the day\'s first read, and a rerun does not duplicate it', function () {
    $this->device->update(['reader_role' => 'first_last']);
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');

    firstLastRead($this, 1, $day->copy()->setTime(5, 0));
    firstLastRead($this, 2, $day->copy()->setTime(11, 0));
    firstLastRead($this, 3, $day->copy()->setTime(14, 0));

    // What the incremental job does: its checkpoint sits after the arrival.
    $reconstruct = app(ReconstructAttendanceSessionsAction::class);
    $reconstruct->handle($this->employee, $day->copy()->setTime(10, 0), $day->copy()->endOfDay(), $this->actor);
    $reconstruct->handle($this->employee, $day->copy()->setTime(12, 0), $day->copy()->endOfDay(), $this->actor);

    $live = AttendanceSession::query()->where('status', '!=', 'superseded')->get();

    expect($live)->toHaveCount(1)
        ->and($live[0]->raw_duration_minutes)->toBe(540);
});

test('an access-only reader takes no part in attendance and raises nothing', function () {
    $this->device->update(['reader_role' => 'access_only']);
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');

    firstLastRead($this, 1, $day->copy()->setTime(5, 0));
    firstLastRead($this, 2, $day->copy()->setTime(14, 0));

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle($this->employee, $day, $day->copy()->endOfDay(), $this->actor);

    expect($sessions)->toBe([])
        ->and(AttendanceAnomaly::query()->count())->toBe(0);
});

test('gate reads on an access-only reader do not disturb the attendance reader\'s day', function () {
    $this->device->update(['reader_role' => 'access_only']);
    $attendance = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
        'reader_role' => 'first_last',
    ]);
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');

    firstLastRead($this, 1, $day->copy()->setTime(4, 50));
    firstLastRead($this, 2, $day->copy()->setTime(5, 0), $attendance);
    firstLastRead($this, 3, $day->copy()->setTime(14, 0), $attendance);
    firstLastRead($this, 4, $day->copy()->setTime(14, 10));

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle($this->employee, $day, $day->copy()->endOfDay(), $this->actor);

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->fresh()->raw_duration_minutes)->toBe(540);
});

test('reads taken before a reader was configured are not reported once it has been', function () {
    $day = Carbon::parse('2026-09-21 00:00:00', 'UTC');
    $this->device->update(['reader_role' => 'unspecified']);
    firstLastRead($this, 1, $day->copy()->setTime(5, 0));

    $this->device->update(['reader_role' => 'access_only']);

    $sessions = app(ReconstructAttendanceSessionsAction::class)->handle($this->employee, $day, $day->copy()->endOfDay(), $this->actor);

    expect($sessions)->toBe([])
        ->and(AttendanceAnomaly::query()->where('anomaly_type', 'undirected_reader')->exists())->toBeFalse();
});
