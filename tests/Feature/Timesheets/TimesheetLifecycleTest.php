<?php

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Timesheets\Actions\ApproveTimesheetAction;
use App\Domain\Timesheets\Actions\GenerateTimesheetForPayPeriodAction;
use App\Domain\Timesheets\Actions\SubmitTimesheetAction;
use App\Domain\Timesheets\Exceptions\SelfApprovalNotAllowedException;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

pest()->group('timesheets');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->finance = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->finance->assignRole('finance');

    $this->manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->manager->assignRole('project_manager');

    $this->employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $this->payPeriod = PayPeriod::factory()->create(['organization_id' => $this->organization->id]);
    $project = Project::factory()->create(['organization_id' => $this->organization->id]);
    RateHistory::factory()->create([
        'organization_id' => $this->organization->id,
        'employee_id' => $this->employee->id,
        'project_id' => null,
        'rate_type' => 'hourly',
        'amount' => '15.00',
        'effective_from' => $this->payPeriod->starts_on,
    ]);

    $workDate = Carbon::instance($this->payPeriod->starts_on)->addDay();
    AttendanceSession::factory()->create([
        'organization_id' => $this->organization->id,
        'employee_id' => $this->employee->id,
        'project_id' => $project->id,
        'clock_in_at' => $workDate->copy()->setTime(9, 0),
        'clock_out_at' => $workDate->copy()->setTime(17, 0),
        'work_date' => $workDate->toDateString(),
        'raw_duration_minutes' => 480,
        'payable_minutes' => 480,
        'status' => 'closed',
    ]);
});

test('generating a timesheet builds lines from closed sessions in the pay period, and reruns do not duplicate them', function () {
    $action = app(GenerateTimesheetForPayPeriodAction::class);

    $timesheet = $action->handle($this->employee, $this->payPeriod);
    expect($timesheet->lines()->count())->toBe(1)
        ->and((int) $timesheet->lines()->sum('payable_minutes'))->toBe(480);

    $again = $action->handle($this->employee, $this->payPeriod);
    expect($again->id)->toBe($timesheet->id)
        ->and($again->lines()->count())->toBe(1);
});

test('full timesheet lifecycle: draft to submitted to approved to locked', function () {
    $timesheet = app(GenerateTimesheetForPayPeriodAction::class)->handle($this->employee, $this->payPeriod);

    $this->actingAs($this->manager)->post(route('timesheets.submit', $timesheet), [
        'version' => $timesheet->version,
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $timesheet->refresh();
    expect($timesheet->status)->toBe('submitted');

    $this->actingAs($this->finance)->post(route('timesheets.approve', $timesheet), [
        'version' => $timesheet->version,
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $timesheet->refresh();
    expect($timesheet->status)->toBe('approved')
        ->and($timesheet->source_sessions_version_snapshot)->not->toBeNull();

    $this->actingAs($this->finance)->post(route('timesheets.lock', $timesheet), [
        'version' => $timesheet->version,
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($timesheet->fresh()->status)->toBe('locked');
});

test('a rejected timesheet returns to draft with a reason', function () {
    $timesheet = app(GenerateTimesheetForPayPeriodAction::class)->handle($this->employee, $this->payPeriod);

    $this->actingAs($this->manager)->post(route('timesheets.submit', $timesheet), ['version' => $timesheet->version]);
    CurrentOrganization::set($this->organization->id);
    $timesheet->refresh();

    $this->actingAs($this->finance)->post(route('timesheets.reject', $timesheet), [
        'version' => $timesheet->version,
        'reason' => 'არასწორი პროექტი',
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $timesheet->refresh();
    expect($timesheet->status)->toBe('draft')
        ->and($timesheet->rejected_reason)->toBe('არასწორი პროექტი');
});

test('an employee cannot approve their own timesheet', function () {
    $selfUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $selfUser->assignRole('finance');
    $this->employee->update(['user_id' => $selfUser->id]);

    $timesheet = app(GenerateTimesheetForPayPeriodAction::class)->handle($this->employee, $this->payPeriod);
    app(SubmitTimesheetAction::class)->handle($timesheet, $this->finance, $timesheet->version);
    CurrentOrganization::set($this->organization->id);
    $timesheet->refresh();

    expect(fn () => app(ApproveTimesheetAction::class)->handle($timesheet, $selfUser, $timesheet->version))
        ->toThrow(SelfApprovalNotAllowedException::class);
});
