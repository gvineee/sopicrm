<?php

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\Actions\ApprovePayRunAction;
use App\Domain\Payroll\Actions\CalculatePayRunAction;
use App\Domain\Payroll\Actions\CreatePayRunAction;
use App\Domain\Payroll\Actions\ReviewPayRunAction;
use App\Domain\Payroll\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Timesheets\Actions\ApproveTimesheetAction;
use App\Domain\Timesheets\Actions\GenerateTimesheetForPayPeriodAction;
use App\Domain\Timesheets\Actions\SubmitTimesheetAction;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

pest()->group('payroll');

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

    $this->employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $this->payPeriod = PayPeriod::factory()->create(['organization_id' => $this->organization->id]);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);
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
        'project_id' => $this->project->id,
        'clock_in_at' => $workDate->copy()->setTime(9, 0),
        'clock_out_at' => $workDate->copy()->setTime(17, 0),
        'work_date' => $workDate->toDateString(),
        'raw_duration_minutes' => 480,
        'payable_minutes' => 480,
        'status' => 'closed',
    ]);

    $timesheet = app(GenerateTimesheetForPayPeriodAction::class)->handle($this->employee, $this->payPeriod);
    app(SubmitTimesheetAction::class)->handle($timesheet, $this->finance, $timesheet->version);
    CurrentOrganization::set($this->organization->id);
    $timesheet->refresh();
    app(ApproveTimesheetAction::class)->handle($timesheet, $this->finance, $timesheet->version);
    CurrentOrganization::set($this->organization->id);
});

test('calculating a pay run from an approved hourly timesheet produces the spec example: 480 min x 15 GEL/hr = 120.00 GEL', function () {
    $payRun = app(CreatePayRunAction::class)->execute($this->payPeriod, $this->finance);

    $this->actingAs($this->finance)->post(route('payroll.pay-runs.calculate', $payRun))->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $payRun->refresh();
    $line = $payRun->lines()->sole();

    expect($payRun->status)->toBe('calculated')
        ->and((string) $line->gross_amount)->toBe('120.00')
        ->and((string) $line->net_amount)->toBe('120.00');
});

test('full pay run lifecycle: calculated to reviewed to approved to locked', function () {
    $payRun = app(CreatePayRunAction::class)->execute($this->payPeriod, $this->finance);
    $this->actingAs($this->finance)->post(route('payroll.pay-runs.calculate', $payRun));
    CurrentOrganization::set($this->organization->id);
    $payRun->refresh();

    $this->actingAs($this->finance)->post(route('payroll.pay-runs.review', $payRun), ['version' => $payRun->version])->assertRedirect();
    CurrentOrganization::set($this->organization->id);
    $payRun->refresh();
    expect($payRun->status)->toBe('reviewed');

    $this->actingAs($this->finance)->post(route('payroll.pay-runs.approve', $payRun), ['version' => $payRun->version])->assertRedirect();
    CurrentOrganization::set($this->organization->id);
    $payRun->refresh();
    expect($payRun->status)->toBe('approved');

    $this->actingAs($this->finance)->post(route('payroll.pay-runs.lock', $payRun), ['version' => $payRun->version])->assertRedirect();
    CurrentOrganization::set($this->organization->id);
    expect($payRun->fresh()->status)->toBe('locked');
});

test('an employee cannot approve a pay run containing their own line', function () {
    $selfUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $selfUser->assignRole('finance');
    $this->employee->update(['user_id' => $selfUser->id]);

    $payRun = app(CreatePayRunAction::class)->execute($this->payPeriod, $this->finance);
    app(CalculatePayRunAction::class)->execute($payRun, $this->finance);
    CurrentOrganization::set($this->organization->id);
    $payRun->refresh();
    app(ReviewPayRunAction::class)->execute($payRun, $this->finance, $payRun->version);
    CurrentOrganization::set($this->organization->id);
    $payRun->refresh();

    expect(fn () => app(ApprovePayRunAction::class)->execute($payRun, $selfUser, $payRun->version))
        ->toThrow(SelfApprovalNotAllowedException::class);
});
