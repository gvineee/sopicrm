<?php

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Timesheets\Actions\GenerateTimesheetForPayPeriodAction;
use App\Domain\Timesheets\Actions\SendTimesheetEmailAction;
use App\Domain\Timesheets\Exceptions\EmailDeliveryNotRetryableException;
use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use App\Jobs\Timesheets\SendTimesheetEmailJob;
use App\Mail\TimesheetSnapshotMail;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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

    $this->employeeUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'email' => 'giorgi@example.test',
    ]);

    $this->employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->employeeUser->id,
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

test('the preview endpoint returns the default recipient and subject without sending anything', function () {
    Mail::fake();

    $response = $this->actingAs($this->finance)->get(route('timesheets.email.preview', $this->timesheet));

    $response->assertOk();
    $response->assertJson([
        'recipient_email' => 'giorgi@example.test',
        'recipient_user_id' => $this->employeeUser->id,
    ]);
    Mail::assertNothingSent();
    expect(TimesheetEmailDelivery::query()->count())->toBe(0);
});

test('sending creates one queued delivery, dispatches the job, and the job marks it sent', function () {
    Mail::fake();
    Queue::fake();

    $response = $this->actingAs($this->finance)->post(route('timesheets.email.send', $this->timesheet), [
        'recipient_email' => 'giorgi@example.test',
        'subject' => 'თქვენი ტაბელი',
    ]);

    $response->assertRedirect();

    // The HTTP test client runs the full middleware stack including
    // SetCurrentOrganization::terminate(), which clears CurrentOrganization
    // once the simulated request finishes — re-establish it here before
    // querying tenant-scoped models directly.
    CurrentOrganization::set($this->organization->id);

    $delivery = TimesheetEmailDelivery::query()->sole();
    expect($delivery->status)->toBe('queued')
        ->and($delivery->timesheet_version_at_send)->toBe($this->timesheet->version)
        ->and($delivery->recipient_email)->toBe('giorgi@example.test');

    Queue::assertPushed(SendTimesheetEmailJob::class, 1);
    Queue::assertPushed(function (SendTimesheetEmailJob $job) use ($delivery) {
        return $job->organizationId === $this->organization->id && $job->deliveryId === $delivery->id;
    });

    Mail::assertNothingSent(); // Queue::fake() intercepted dispatch — the job never ran.

    // Run the job for real (mirrors tests/Feature/Auth/OutboxTransactionTest.php's
    // house pattern of directly invoking ->handle() to prove the job's own logic).
    Mail::fake();
    CurrentOrganization::clear();
    (new SendTimesheetEmailJob($this->organization->id, $delivery->id))->handle();

    $delivery->refresh();
    expect($delivery->status)->toBe('sent')
        ->and($delivery->sent_at)->not->toBeNull();

    Mail::assertSent(TimesheetSnapshotMail::class, function (TimesheetSnapshotMail $mail) {
        return $mail->hasTo('giorgi@example.test');
    });
});

test('a second send of the same version reuses the existing snapshot attachment', function () {
    Mail::fake();

    app(SendTimesheetEmailAction::class)->send($this->timesheet, 'a@example.test', null, 'subject A', $this->finance);
    app(SendTimesheetEmailAction::class)->send($this->timesheet, 'b@example.test', null, 'subject B', $this->finance);

    expect(TimesheetEmailDelivery::query()->count())->toBe(2)
        ->and(Attachment::query()->where('owner_type', Timesheet::class)->where('owner_id', $this->timesheet->id)->count())->toBe(1);

    $attachmentIds = TimesheetEmailDelivery::query()->pluck('attachment_id')->unique();
    expect($attachmentIds)->toHaveCount(1);
});

test('a version bump between two sends creates a second snapshot attachment', function () {
    Mail::fake();

    app(SendTimesheetEmailAction::class)->send($this->timesheet, 'a@example.test', null, 'subject A', $this->finance);

    // Any real attribute change triggers HasVersion's saving hook, which
    // increments `version` automatically — this simulates the timesheet
    // being reprocessed/edited after its first snapshot was taken.
    $this->timesheet->update(['rejected_reason' => 'reprocessed for test']);
    $this->timesheet->refresh();

    app(SendTimesheetEmailAction::class)->send($this->timesheet, 'a@example.test', null, 'subject A v2', $this->finance);

    expect(Attachment::query()->where('owner_type', Timesheet::class)->where('owner_id', $this->timesheet->id)->count())->toBe(2);
});

test('a failed send is recorded with a reason and can be retried; a sent delivery cannot', function () {
    // Queue::fake() first, so send()'s post-commit dispatch is intercepted
    // rather than run synchronously (this test environment's
    // QUEUE_CONNECTION=sync would otherwise run the job immediately) —
    // matches this file's own established pattern of asserting dispatch,
    // then invoking ->handle() directly to control exactly when/how it runs.
    Mail::fake();
    Queue::fake();
    $delivery = app(SendTimesheetEmailAction::class)->send($this->timesheet, 'a@example.test', null, 'subject', $this->finance);

    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp connection refused'));
    CurrentOrganization::clear();
    expect(fn () => (new SendTimesheetEmailJob($this->organization->id, $delivery->id))->handle())
        ->toThrow(RuntimeException::class);
    CurrentOrganization::set($this->organization->id);

    $delivery->refresh();
    expect($delivery->status)->toBe('failed')
        ->and($delivery->failed_reason)->toContain('smtp connection refused');

    Queue::fake();
    $retried = app(SendTimesheetEmailAction::class)->retry($delivery, $this->finance);
    expect($retried->status)->toBe('queued');
    Queue::assertPushed(SendTimesheetEmailJob::class, 1);

    $retried->update(['status' => 'sent']);
    expect(fn () => app(SendTimesheetEmailAction::class)->retry($retried, $this->finance))
        ->toThrow(EmailDeliveryNotRetryableException::class);
});

test('a user without timesheets.timesheets.approve is denied every email route directly', function () {
    $projectManager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $projectManager->assignRole('project_manager');

    $delivery = TimesheetEmailDelivery::factory()->create([
        'organization_id' => $this->organization->id,
        'timesheet_id' => $this->timesheet->id,
        'attachment_id' => Attachment::factory()->create(['organization_id' => $this->organization->id])->id,
        'requested_by_user_id' => $this->finance->id,
        'status' => 'failed',
    ]);

    $this->actingAs($projectManager)->get(route('timesheets.email.preview', $this->timesheet))->assertForbidden();
    $this->actingAs($projectManager)->post(route('timesheets.email.send', $this->timesheet), [
        'recipient_email' => 'x@example.test',
        'subject' => 'x',
    ])->assertForbidden();
    $this->actingAs($projectManager)->post(route('timesheets.email.retry', [$this->timesheet, $delivery]))->assertForbidden();
});

test('delivery history is isolated per organization', function () {
    $otherOrg = Organization::factory()->create();
    CurrentOrganization::set($otherOrg->id);
    $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
    $otherDelivery = TimesheetEmailDelivery::factory()->create([
        'organization_id' => $otherOrg->id,
        'attachment_id' => Attachment::factory()->create(['organization_id' => $otherOrg->id])->id,
        'requested_by_user_id' => $otherUser->id,
    ]);
    CurrentOrganization::set($this->organization->id);

    expect(TimesheetEmailDelivery::query()->pluck('id'))->not->toContain($otherDelivery->id);
});
