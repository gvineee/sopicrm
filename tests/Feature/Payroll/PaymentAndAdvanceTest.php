<?php

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\Actions\ApprovePayRunAction;
use App\Domain\Payroll\Actions\CalculatePayRunAction;
use App\Domain\Payroll\Actions\CreatePayRunAction;
use App\Domain\Payroll\Actions\GrantAdvanceAction;
use App\Domain\Payroll\Actions\RecordPaymentAction;
use App\Domain\Payroll\Actions\ReviewPayRunAction;
use App\Domain\Payroll\Exceptions\AdvanceOverAllocationException;
use App\Domain\Payroll\Exceptions\AdvanceOwnershipMismatchException;
use App\Domain\Payroll\Exceptions\PaymentAllocationExceedsBalanceException;
use App\Domain\Payroll\Exceptions\PaymentCurrencyMismatchException;
use App\Domain\Payroll\Models\Payment;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Services\PayrollBalanceService;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Timesheets\Actions\ApproveTimesheetAction;
use App\Domain\Timesheets\Actions\GenerateTimesheetForPayPeriodAction;
use App\Domain\Timesheets\Actions\SubmitTimesheetAction;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

    $timesheet = app(GenerateTimesheetForPayPeriodAction::class)->handle($this->employee, $this->payPeriod);
    app(SubmitTimesheetAction::class)->handle($timesheet, $this->finance, $timesheet->version);
    CurrentOrganization::set($this->organization->id);
    $timesheet->refresh();
    app(ApproveTimesheetAction::class)->handle($timesheet, $this->finance, $timesheet->version);
    CurrentOrganization::set($this->organization->id);

    $payRun = app(CreatePayRunAction::class)->execute($this->payPeriod, $this->finance);
    app(CalculatePayRunAction::class)->execute($payRun, $this->finance);
    CurrentOrganization::set($this->organization->id);
    $payRun->refresh();
    app(ReviewPayRunAction::class)->execute($payRun, $this->finance, $payRun->version);
    CurrentOrganization::set($this->organization->id);
    $payRun->refresh();
    app(ApprovePayRunAction::class)->execute($payRun, $this->finance, $payRun->version);
    CurrentOrganization::set($this->organization->id);
});

test('outstanding balance reflects an approved 120 GEL line before any payment', function () {
    $balance = app(PayrollBalanceService::class)->outstandingForEmployee($this->employee->id);

    expect($balance)->toBe('120.00');
});

test('recording a payment reduces the outstanding balance and a second overpayment is rejected', function () {
    app(RecordPaymentAction::class)->execute($this->employee, '50.00', 'GEL', 'cash', null, null, $this->finance);
    CurrentOrganization::set($this->organization->id);

    expect(app(PayrollBalanceService::class)->outstandingForEmployee($this->employee->id))->toBe('70.00');

    expect(fn () => app(RecordPaymentAction::class)->execute($this->employee, '100.00', 'GEL', 'cash', null, null, $this->finance))
        ->toThrow(PaymentAllocationExceedsBalanceException::class);
});

test('an advance is deducted at most once: overpaying it is rejected and it settles to fully_deducted at exactly the granted amount', function () {
    $advance = app(GrantAdvanceAction::class)->execute($this->employee, '30.00', 'GEL', 'საჭირო თანხა', $this->finance);
    CurrentOrganization::set($this->organization->id);

    expect(fn () => app(RecordPaymentAction::class)->execute($this->employee, '40.00', 'GEL', 'cash', null, null, $this->finance, $advance->id))
        ->toThrow(AdvanceOverAllocationException::class);

    app(RecordPaymentAction::class)->execute($this->employee, '30.00', 'GEL', 'cash', null, null, $this->finance, $advance->id);
    CurrentOrganization::set($this->organization->id);

    expect($advance->fresh()->status)->toBe('fully_deducted')
        ->and(app(PayrollBalanceService::class)->remainingOnAdvance($advance->fresh()))->toBe('0.00')
        // spec formula: advance deductions also reduce the employee's payroll
        // outstanding balance, exactly once.
        ->and(app(PayrollBalanceService::class)->outstandingForEmployee($this->employee->id))->toBe('90.00');
});

test('MONEY-01/D2: a payment naming a different employee\'s advance is rejected, not silently applied', function () {
    $otherEmployee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $otherAdvance = app(GrantAdvanceAction::class)->execute($otherEmployee, '20.00', 'GEL', 'სხვისი ავანსი', $this->finance);
    CurrentOrganization::set($this->organization->id);

    expect(fn () => app(RecordPaymentAction::class)->execute($this->employee, '10.00', 'GEL', 'cash', null, null, $this->finance, $otherAdvance->id))
        ->toThrow(AdvanceOwnershipMismatchException::class);

    expect(app(PayrollBalanceService::class)->remainingOnAdvance($otherAdvance->fresh()))->toBe('20.00');
});

test('MONEY-01/D2: a non-GEL payment currency is rejected', function () {
    expect(fn () => app(RecordPaymentAction::class)->execute($this->employee, '10.00', 'USD', 'cash', null, null, $this->finance))
        ->toThrow(PaymentCurrencyMismatchException::class);
});

test('MONEY-01/D1: retrying the same request_id returns the original payment instead of recording a second one', function () {
    $requestId = (string) Str::uuid();

    $first = app(RecordPaymentAction::class)->execute($this->employee, '50.00', 'GEL', 'cash', null, null, $this->finance, null, $requestId);
    CurrentOrganization::set($this->organization->id);
    $second = app(RecordPaymentAction::class)->execute($this->employee, '50.00', 'GEL', 'cash', null, null, $this->finance, null, $requestId);
    CurrentOrganization::set($this->organization->id);

    expect($second->id)->toBe($first->id)
        ->and(Payment::query()->count())->toBe(1)
        ->and(app(PayrollBalanceService::class)->outstandingForEmployee($this->employee->id))->toBe('70.00');
});
