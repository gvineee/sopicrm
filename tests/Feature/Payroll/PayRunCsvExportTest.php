<?php

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\Actions\CalculatePayRunAction;
use App\Domain\Payroll\Actions\CreatePayRunAction;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\PayRun;
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
});

/**
 * @return array{0: Employee, 1: PayRun}
 */
function calculatedPayRunForCsvTest(string $organizationId, User $finance, ?string $employeeFirstName = null): array
{
    $employee = Employee::factory()->create([
        'organization_id' => $organizationId,
        'first_name' => $employeeFirstName ?? fake()->firstName(),
    ]);
    $payPeriod = PayPeriod::factory()->create(['organization_id' => $organizationId]);
    $project = Project::factory()->create(['organization_id' => $organizationId]);
    RateHistory::factory()->create([
        'organization_id' => $organizationId,
        'employee_id' => $employee->id,
        'project_id' => null,
        'rate_type' => 'hourly',
        'amount' => '15.00',
        'effective_from' => $payPeriod->starts_on,
    ]);

    $workDate = Carbon::instance($payPeriod->starts_on)->addDay();
    AttendanceSession::factory()->create([
        'organization_id' => $organizationId,
        'employee_id' => $employee->id,
        'project_id' => $project->id,
        'clock_in_at' => $workDate->copy()->setTime(9, 0),
        'clock_out_at' => $workDate->copy()->setTime(17, 0),
        'work_date' => $workDate->toDateString(),
        'raw_duration_minutes' => 480,
        'payable_minutes' => 480,
        'status' => 'closed',
    ]);

    $timesheet = app(GenerateTimesheetForPayPeriodAction::class)->handle($employee, $payPeriod);
    app(SubmitTimesheetAction::class)->handle($timesheet, $finance, $timesheet->version);
    CurrentOrganization::set($organizationId);
    $timesheet->refresh();
    app(ApproveTimesheetAction::class)->handle($timesheet, $finance, $timesheet->version);
    CurrentOrganization::set($organizationId);

    $payRun = app(CreatePayRunAction::class)->execute($payPeriod, $finance);
    app(CalculatePayRunAction::class)->execute($payRun, $finance);
    CurrentOrganization::set($organizationId);
    $payRun->refresh();

    return [$employee, $payRun];
}

test('an authorized user can download a real CSV export for a pay run', function () {
    [$employee, $payRun] = calculatedPayRunForCsvTest($this->organization->id, $this->finance);

    $response = $this->actingAs($this->finance)->get("/payroll/pay-runs/{$payRun->id}/export-csv");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');

    $csv = $response->getContent();
    expect($csv)->toContain('პერიოდი')
        ->and($csv)->toContain('თანამშრომელი')
        ->and($csv)->toContain(trim($employee->first_name.' '.$employee->last_name))
        // 480 minutes / 60 = 8.00 hours at 15.00 GEL/hr = 120.00 GEL accrual.
        ->and($csv)->toContain('8.00')
        ->and($csv)->toContain('120.00');
});

test('a free-text field starting with a formula character is neutralized against CSV/spreadsheet injection', function () {
    $maliciousName = "=cmd|'/c calc'!A1";
    [, $payRun] = calculatedPayRunForCsvTest($this->organization->id, $this->finance, employeeFirstName: $maliciousName);

    $response = $this->actingAs($this->finance)->get("/payroll/pay-runs/{$payRun->id}/export-csv");

    $response->assertOk();
    $csv = $response->getContent();

    // The formula string must never appear directly after a field
    // delimiter/quote with no defensive prefix — it must always be
    // immediately preceded by a defensive `'` so no spreadsheet application
    // interprets it as a formula on open. fputcsv may additionally wrap the
    // whole field in its own `"..."` CSV-quoting (a separate, RFC-4180
    // layer that spreadsheet apps strip before reading cell content); the
    // defensive `'` must survive either way, immediately preceding `=`.
    expect($csv)->not->toContain(','.$maliciousName) // unescaped, right after the delimiter
        ->and($csv)->not->toContain('"'.$maliciousName) // unescaped, right after fputcsv's own quote
        ->and($csv)->toContain("'".$maliciousName); // the defended version, present somewhere
});

test('a user without payroll.pay-runs.view is denied the CSV export on a direct request', function () {
    [, $payRun] = calculatedPayRunForCsvTest($this->organization->id, $this->finance);

    $employeeRoleUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $employeeRoleUser->assignRole('employee');

    $this->actingAs($employeeRoleUser)->get("/payroll/pay-runs/{$payRun->id}/export-csv")->assertForbidden();
});

test('a pay run from a different organization cannot be exported via the route model binding', function () {
    [, $payRun] = calculatedPayRunForCsvTest($this->organization->id, $this->finance);

    $otherOrg = Organization::factory()->create();
    CurrentOrganization::set($otherOrg->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($otherOrg->id);

    $otherUser = User::factory()->create([
        'organization_id' => $otherOrg->id,
        'current_organization_id' => $otherOrg->id,
    ]);
    $otherUser->assignRole('finance');
    CurrentOrganization::set($otherOrg->id);

    $this->actingAs($otherUser)->get("/payroll/pay-runs/{$payRun->id}/export-csv")->assertNotFound();
});
