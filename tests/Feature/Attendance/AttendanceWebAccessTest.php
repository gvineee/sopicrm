<?php

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('attendance');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
});

test('project manager can create a shift template and assign an employee to it', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $manager->assignRole('project_manager');
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($manager)->post(route('attendance.shift-templates.store'), [
        'name' => 'დღის ცვლა',
        'starts_at_local' => '09:00',
        'ends_at_local' => '18:00',
        'crosses_midnight' => false,
        'scheduled_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        'break_policy' => ['type' => 'fixed', 'minutes' => 60],
        'allowed_late_minutes' => 5,
        'rounding_policy' => ['mode' => 'none'],
    ])->assertRedirect(route('attendance.shift-templates.index'));

    CurrentOrganization::set($this->organization->id);
    $template = ShiftTemplate::query()->where('name', 'დღის ცვლა')->sole();

    $this->actingAs($manager)->post(route('attendance.shift-assignments.store'), [
        'employee_id' => $employee->id,
        'shift_template_id' => $template->id,
        'effective_from' => now()->toDateString(),
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect(ShiftAssignment::query()->where('employee_id', $employee->id)->where('shift_template_id', $template->id)->exists())->toBeTrue();
});

test('finance can view but not manage shift templates', function () {
    $finance = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $finance->assignRole('finance');

    $this->actingAs($finance)
        ->get(route('attendance.shift-templates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Attendance/ShiftTemplates/Index')->where('canManage', false));

    $this->actingAs($finance)->post(route('attendance.shift-templates.store'), [
        'name' => 'უნებართვო შაბლონი',
        'starts_at_local' => '09:00',
        'ends_at_local' => '18:00',
        'crosses_midnight' => false,
        'scheduled_days' => ['mon'],
        'break_policy' => ['type' => 'fixed', 'minutes' => 0],
        'allowed_late_minutes' => 0,
        'rounding_policy' => ['mode' => 'none'],
    ])->assertForbidden();
});

test('resolving an anomaly requires the resolve permission and records who resolved it', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $manager->assignRole('project_manager');
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $anomaly = AttendanceAnomaly::factory()->create([
        'organization_id' => $this->organization->id,
        'employee_id' => $employee->id,
        'anomaly_type' => 'missing_out',
    ]);

    $this->actingAs($manager)->post(route('attendance.anomalies.resolve', $anomaly), [
        'resolution_note' => 'დადასტურდა HR-თან ტელეფონით.',
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $anomaly->refresh();
    expect($anomaly->resolved_at)->not->toBeNull()
        ->and($anomaly->resolved_by_user_id)->toBe($manager->id);
});

test('organization B cannot see organization A\'s attendance sessions', function () {
    $orgB = Organization::factory()->create();
    $managerA = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $managerA->assignRole('project_manager');

    $managerB = User::factory()->create([
        'organization_id' => $orgB->id,
        'current_organization_id' => $orgB->id,
    ]);
    CurrentOrganization::set($orgB->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($orgB->id);
    $managerB->assignRole('project_manager');

    CurrentOrganization::set($this->organization->id);
    $employeeA = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $templateA = ShiftTemplate::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($managerB)
        ->get(route('attendance.shift-templates.edit', $templateA))
        ->assertNotFound();
});
