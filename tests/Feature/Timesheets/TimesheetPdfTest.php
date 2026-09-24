<?php

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Timesheets\Actions\GenerateTimesheetForPayPeriodAction;
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

    // TIMESHEET-01 acceptance criterion ("Georgian text/fonts"): a real
    // Georgian name, not transliterated ASCII, so the extraction assertion
    // below actually proves something about Georgian glyph rendering.
    $this->employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'first_name' => 'გიორგი',
        'last_name' => 'მაისურაძე',
    ]);
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

    $this->timesheet = app(GenerateTimesheetForPayPeriodAction::class)->handle($this->employee, $this->payPeriod);
    CurrentOrganization::set($this->organization->id);
});

test('an authorized user can stream the timesheet PDF inline', function () {
    $response = $this->actingAs($this->finance)->get(route('timesheets.pdf', $this->timesheet));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect(strlen($response->getContent()))->toBeGreaterThan(1000);
});

test('a user without timesheets.timesheets.view is denied the PDF route', function () {
    $employeeRoleUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $employeeRoleUser->assignRole('employee');

    $this->actingAs($employeeRoleUser)->get(route('timesheets.pdf', $this->timesheet))->assertForbidden();
});

test('a user from a different organization cannot reach the PDF via the route model binding', function () {
    $otherOrg = Organization::factory()->create();
    CurrentOrganization::set($otherOrg->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($otherOrg->id);

    $otherUser = User::factory()->create([
        'organization_id' => $otherOrg->id,
        'current_organization_id' => $otherOrg->id,
    ]);
    $otherUser->assignRole('finance');
    CurrentOrganization::set($this->organization->id);

    $this->actingAs($otherUser)->get(route('timesheets.pdf', $this->timesheet))->assertNotFound();
});

/**
 * TIMESHEET-01's own acceptance wording explicitly names "Georgian
 * text/fonts" — a PDF that merely doesn't throw is not proof the font
 * rendered real glyphs (dompdf silently renders empty boxes for an
 * unregistered/glyph-incomplete font, no exception). This test proves it
 * for real: extracts the PDF's own text layer with `pdftotext` (xpdf/
 * poppler) and asserts the employee's actual Georgian name string comes
 * back byte-for-byte, which is only possible if dompdf embedded a correct
 * ToUnicode CMap for NotoSansGeorgian — confirmed manually during this
 * ticket (see docs/decisions.md DEC-086) with the exact same technique
 * before this test existed. `pdftotext` is not guaranteed present on every
 * CI runner; this test SKIPS (not silently passes) with an explicit reason
 * when it's unavailable, rather than asserting nothing.
 */
test('the generated PDF text layer contains the real Georgian employee name, not empty glyph boxes', function () {
    exec('pdftotext -v 2>&1', $output, $exitCode);
    if ($exitCode !== 0 && $exitCode !== 99) {
        // xpdf's pdftotext prints its version and exits non-zero for -v in
        // some builds; only treat "command not found" (typically 127, or
        // any failure to execute at all) as truly unavailable.
        // `$this->markTestSkipped()`, not `test()->skip()`: inside a test
        // closure `test()` is not the running test case, so the intended skip
        // died with "Call to undefined method Tests\TestCase::skip()" — i.e.
        // on any machine without pdftotext this test ERRORED instead of
        // skipping, which is exactly what the docblock above says it must not
        // do.
        $this->markTestSkipped('pdftotext (poppler/xpdf) not available in this environment — cannot assert PDF glyph-level text extraction here. Verified manually during TIMESHEET-01 (docs/decisions.md DEC-086); re-enable this assertion wherever pdftotext is installed.');
    }

    $response = $this->actingAs($this->finance)->get(route('timesheets.pdf', $this->timesheet));
    $response->assertOk();

    $tmpPdf = tempnam(sys_get_temp_dir(), 'tsheet').'.pdf';
    file_put_contents($tmpPdf, $response->getContent());

    $tmpTxt = $tmpPdf.'.txt';
    exec('pdftotext -enc UTF-8 '.escapeshellarg($tmpPdf).' '.escapeshellarg($tmpTxt));
    $text = file_exists($tmpTxt) ? file_get_contents($tmpTxt) : '';

    @unlink($tmpPdf);
    @unlink($tmpTxt);

    expect($text)->toContain('გიორგი')
        ->and($text)->toContain('მაისურაძე');
});
