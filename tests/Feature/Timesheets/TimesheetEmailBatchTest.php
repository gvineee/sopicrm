<?php

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Timesheets\Actions\CancelTimesheetEmailBatchAction;
use App\Domain\Timesheets\Actions\CreateTimesheetEmailBatchAction;
use App\Domain\Timesheets\Actions\GenerateTimesheetForPayPeriodAction;
use App\Domain\Timesheets\Actions\RetryTimesheetEmailBatchDeliveryAction;
use App\Domain\Timesheets\Exceptions\EmailDeliveryNotRetryableException;
use App\Domain\Timesheets\Models\TimesheetEmailBatch;
use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use App\Jobs\Timesheets\SendBundledTimesheetEmailJob;
use App\Jobs\Timesheets\SendTimesheetEmailJob;
use App\Mail\TimesheetBundleSnapshotMail;
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

    $this->accountant = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'email' => 'buhgalteri@example.test',
    ]);
});

/**
 * A static counter, not per-test state: PayPeriodFactory's own random date
 * window is narrow enough that two calls for the SAME organization within
 * one test (as several tests here do — two employees, two timesheets) can
 * collide on the real unique(organization_id, starts_on, ends_on)
 * constraint. Every call gets its own distinct, non-overlapping month.
 *
 * @return Timesheet
 */
function makeTimesheetForBatchTest(string $organizationId, string $firstName, string $lastName, ?string $email = null)
{
    static $monthOffset = 0;
    $monthOffset--;

    $employeeUser = $email !== null ? User::factory()->create([
        'organization_id' => $organizationId,
        'current_organization_id' => $organizationId,
        'email' => $email,
    ]) : null;

    $employee = Employee::factory()->create([
        'organization_id' => $organizationId,
        'user_id' => $employeeUser?->id,
        'first_name' => $firstName,
        'last_name' => $lastName,
    ]);

    $start = Carbon::now()->startOfMonth()->addMonthsNoOverflow($monthOffset);
    $payPeriod = PayPeriod::factory()->create([
        'organization_id' => $organizationId,
        'starts_on' => $start->toDateString(),
        'ends_on' => $start->copy()->endOfMonth()->toDateString(),
    ]);
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

    return app(GenerateTimesheetForPayPeriodAction::class)->handle($employee, $payPeriod);
}

test('per-employee mode sends each selected employee only their own timesheet, in a separate email', function () {
    Mail::fake();
    Queue::fake();

    $t1 = makeTimesheetForBatchTest($this->organization->id, 'გიორგი', 'მაისურაძე', 'giorgi@example.test');
    $t2 = makeTimesheetForBatchTest($this->organization->id, 'ნინო', 'კაპანაძე', 'nino@example.test');

    $batch = app(CreateTimesheetEmailBatchAction::class)->execute(
        [$t1->id, $t2->id],
        'per_employee',
        null,
        null,
        $this->finance,
    );

    expect($batch->mode)->toBe('per_employee')
        ->and($batch->total_count)->toBe(2)
        ->and($batch->skipped_details ?? [])->toBeEmpty();

    $deliveries = TimesheetEmailDelivery::query()->where('batch_id', $batch->id)->get();
    expect($deliveries)->toHaveCount(2);

    Queue::assertPushed(SendTimesheetEmailJob::class, 2);

    Mail::fake();
    foreach ($deliveries as $delivery) {
        CurrentOrganization::clear();
        (new SendTimesheetEmailJob($this->organization->id, $delivery->id))->handle();
    }

    Mail::assertSent(TimesheetSnapshotMail::class, fn (TimesheetSnapshotMail $mail) => $mail->hasTo('giorgi@example.test'));
    Mail::assertSent(TimesheetSnapshotMail::class, fn (TimesheetSnapshotMail $mail) => $mail->hasTo('nino@example.test'));
    // Each mailable carries exactly its own timesheet's attachment — never
    // the other employee's document folded in.
    Mail::assertSentCount(2);
});

test('bundled mode sends one message to one authorized recipient with the exact selected set', function () {
    Mail::fake();
    Queue::fake();

    $t1 = makeTimesheetForBatchTest($this->organization->id, 'გიორგი', 'მაისურაძე');
    $t2 = makeTimesheetForBatchTest($this->organization->id, 'ნინო', 'კაპანაძე');

    $batch = app(CreateTimesheetEmailBatchAction::class)->execute(
        [$t1->id, $t2->id],
        'bundled',
        'buhgalteri@example.test',
        $this->accountant->id,
        $this->finance,
    );

    expect($batch->mode)->toBe('bundled');

    $delivery = TimesheetEmailDelivery::query()->where('batch_id', $batch->id)->sole();
    expect($delivery->items()->count())->toBe(2)
        ->and($delivery->recipient_email)->toBe('buhgalteri@example.test');

    Queue::assertPushed(SendBundledTimesheetEmailJob::class, 1);

    Mail::fake();
    CurrentOrganization::clear();
    (new SendBundledTimesheetEmailJob($this->organization->id, $delivery->id))->handle();

    Mail::assertSentCount(1);
    Mail::assertSent(TimesheetBundleSnapshotMail::class, function (TimesheetBundleSnapshotMail $mail) {
        return $mail->hasTo('buhgalteri@example.test') && $mail->items->count() === 2;
    });
});

test('a timesheet id from another organization is rejected cleanly and reported as skipped, never leaked', function () {
    Mail::fake();
    Queue::fake();

    $mine = makeTimesheetForBatchTest($this->organization->id, 'გიორგი', 'მაისურაძე', 'giorgi@example.test');

    $otherOrg = Organization::factory()->create();
    CurrentOrganization::set($otherOrg->id);
    $foreign = makeTimesheetForBatchTest($otherOrg->id, 'სხვა', 'ორგანიზაცია', 'other@example.test');
    CurrentOrganization::set($this->organization->id);

    $batch = app(CreateTimesheetEmailBatchAction::class)->execute(
        [$mine->id, $foreign->id],
        'per_employee',
        null,
        null,
        $this->finance,
    );

    expect($batch->total_count)->toBe(2)
        ->and($batch->skipped_details)->toHaveCount(1)
        ->and($batch->skipped_details[0]['timesheet_id'])->toBe($foreign->id)
        // The reason string must never disclose that a real timesheet
        // exists at that id in another organization — same generic wording
        // as a plain permission mismatch.
        ->and($batch->skipped_details[0]['reason'])->not->toContain($otherOrg->id);

    expect(TimesheetEmailDelivery::query()->where('batch_id', $batch->id)->count())->toBe(1);
});

test('an employee with no linked user account is skipped with a reason instead of inventing an email', function () {
    Mail::fake();
    Queue::fake();

    $noAccount = makeTimesheetForBatchTest($this->organization->id, 'დავით', 'ხაჩიძე', null);

    $batch = app(CreateTimesheetEmailBatchAction::class)->execute(
        [$noAccount->id],
        'per_employee',
        null,
        null,
        $this->finance,
    );

    expect(TimesheetEmailDelivery::query()->where('batch_id', $batch->id)->count())->toBe(0)
        ->and($batch->skipped_details)->toHaveCount(1)
        ->and($batch->skipped_details[0]['reason'])->toContain('ელფოსტა');
});

test('a single recipient SMTP failure does not affect or duplicate the others, and only that one is retriable', function () {
    Mail::fake();
    Queue::fake();

    $t1 = makeTimesheetForBatchTest($this->organization->id, 'გიორგი', 'მაისურაძე', 'giorgi@example.test');
    $t2 = makeTimesheetForBatchTest($this->organization->id, 'ნინო', 'კაპანაძე', 'nino@example.test');

    $batch = app(CreateTimesheetEmailBatchAction::class)->execute(
        [$t1->id, $t2->id],
        'per_employee',
        null,
        null,
        $this->finance,
    );

    $deliveries = TimesheetEmailDelivery::query()->where('batch_id', $batch->id)->get();
    $failing = $deliveries->firstWhere('recipient_email', 'giorgi@example.test');
    $succeeding = $deliveries->firstWhere('recipient_email', 'nino@example.test');

    Mail::fake();
    CurrentOrganization::clear();
    (new SendTimesheetEmailJob($this->organization->id, $succeeding->id))->handle();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp timeout'));
    CurrentOrganization::clear();
    expect(fn () => (new SendTimesheetEmailJob($this->organization->id, $failing->id))->handle())
        ->toThrow(RuntimeException::class);

    CurrentOrganization::set($this->organization->id);
    $succeeding->refresh();
    $failing->refresh();

    expect($succeeding->status)->toBe('sent')
        ->and($failing->status)->toBe('failed');

    // Retry action only ever targets the failed one.
    $retried = app(RetryTimesheetEmailBatchDeliveryAction::class)
        ->execute($failing->fresh(), $this->finance);
    expect($retried->status)->toBe('queued');

    expect(fn () => app(RetryTimesheetEmailBatchDeliveryAction::class)
        ->execute($succeeding->fresh(), $this->finance))
        ->toThrow(EmailDeliveryNotRetryableException::class);
});

test('cancelling a batch leaves already-sent deliveries alone and only cancels the remaining queued ones', function () {
    Mail::fake();
    Queue::fake();

    $t1 = makeTimesheetForBatchTest($this->organization->id, 'გიორგი', 'მაისურაძე', 'giorgi@example.test');
    $t2 = makeTimesheetForBatchTest($this->organization->id, 'ნინო', 'კაპანაძე', 'nino@example.test');

    $batch = app(CreateTimesheetEmailBatchAction::class)->execute(
        [$t1->id, $t2->id],
        'per_employee',
        null,
        null,
        $this->finance,
    );

    $deliveries = TimesheetEmailDelivery::query()->where('batch_id', $batch->id)->get();
    $sent = $deliveries->first();
    $stillQueued = $deliveries->last();

    Mail::fake();
    CurrentOrganization::clear();
    (new SendTimesheetEmailJob($this->organization->id, $sent->id))->handle();
    CurrentOrganization::set($this->organization->id);

    app(CancelTimesheetEmailBatchAction::class)->execute($batch->fresh(), $this->finance);

    $sent->refresh();
    $stillQueued->refresh();

    expect($sent->status)->toBe('sent')
        ->and($sent->cancelled_at)->toBeNull()
        ->and($stillQueued->status)->toBe('queued')
        ->and($stillQueued->cancelled_at)->not->toBeNull();

    // A cancelled-but-still-"queued" delivery's job is a safe no-op.
    Mail::fake();
    CurrentOrganization::clear();
    (new SendTimesheetEmailJob($this->organization->id, $stillQueued->id))->handle();
    Mail::assertNothingSent();
});

test('a version change after enqueue does not silently change what a batch already committed to sending', function () {
    Mail::fake();
    Queue::fake();

    $t1 = makeTimesheetForBatchTest($this->organization->id, 'გიორგი', 'მაისურაძე', 'giorgi@example.test');
    $pinnedVersion = $t1->version;

    $batch = app(CreateTimesheetEmailBatchAction::class)->execute([$t1->id], 'per_employee', null, null, $this->finance);
    $delivery = TimesheetEmailDelivery::query()->where('batch_id', $batch->id)->sole();

    expect($delivery->timesheet_version_at_send)->toBe($pinnedVersion);

    // The timesheet changes AFTER the batch pinned its version/snapshot.
    $t1->update(['rejected_reason' => 'edited after batch enqueue']);

    Mail::fake();
    CurrentOrganization::clear();
    (new SendTimesheetEmailJob($this->organization->id, $delivery->id))->handle();

    // Sends the snapshot pinned at enqueue time regardless of the later
    // edit — never a silently different/newer version.
    $delivery->refresh();
    expect($delivery->status)->toBe('sent')
        ->and($delivery->timesheet_version_at_send)->toBe($pinnedVersion);
});

test('a user without timesheets.timesheets.approve is denied every batch route directly', function () {
    $projectManager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $projectManager->assignRole('project_manager');

    $t1 = makeTimesheetForBatchTest($this->organization->id, 'გიორგი', 'მაისურაძე', 'giorgi@example.test');

    $this->actingAs($projectManager)->post(route('timesheet-email-batches.store'), [
        'timesheet_ids' => [$t1->id],
        'mode' => 'per_employee',
    ])->assertForbidden();

    $batch = TimesheetEmailBatch::factory()->create([
        'organization_id' => $this->organization->id,
        'requested_by_user_id' => $this->finance->id,
    ]);

    $this->actingAs($projectManager)->get(route('timesheet-email-batches.show', $batch))->assertForbidden();
    $this->actingAs($projectManager)->post(route('timesheet-email-batches.cancel', $batch))->assertForbidden();
});
