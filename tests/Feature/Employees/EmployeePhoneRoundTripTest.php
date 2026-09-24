<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('employees');

/**
 * Acceptance EMP-01 / audit A05: the auditor saw a phone number on an
 * employee's DETAIL page while the EDIT form's phone field appeared empty,
 * and flagged it as an unconfirmed serialization/form-binding suspicion
 * ("შენახვა არ შესრულებულა; მონაცემის დაკარგვა არ დადასტურებულა").
 *
 * Reading the code, `show()` and `edit()` pass the SAME EmployeeResource,
 * and `phone` is not permission-gated in it — so the presumed cause is not
 * reproducible at that layer. These tests establish the ground truth the
 * audit asked for: the value must be exposed to the edit form, and an
 * otherwise-unchanged save must not erase it.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->hr = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->hr->assignRole('hr');

    $this->employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'phone' => '+995 555 12 34 56',
    ]);
});

test('the edit form receives the employee current phone number', function () {
    $this->actingAs($this->hr)
        ->get(route('employees.edit', $this->employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Employees/Edit')
            ->where('employee.phone', '+995 555 12 34 56')
        );
});

test('the detail page and the edit form expose the identical phone value', function () {
    $show = $this->actingAs($this->hr)->get(route('employees.show', $this->employee));
    $edit = $this->actingAs($this->hr)->get(route('employees.edit', $this->employee));

    $shownPhone = $show->viewData('page')['props']['employee']['phone'];
    $editPhone = $edit->viewData('page')['props']['employee']['phone'];

    expect($shownPhone)->toBe('+995 555 12 34 56')
        ->and($editPhone)->toBe($shownPhone);
});

test('saving the employee without touching the phone preserves it', function () {
    $payload = [
        'internal_code' => $this->employee->internal_code,
        'first_name' => $this->employee->first_name,
        'last_name' => $this->employee->last_name,
        // Submitted unchanged, exactly as the edit form would round-trip it.
        'phone' => $this->employee->phone,
        'status' => $this->employee->status,
    ];

    $this->actingAs($this->hr)
        ->put(route('employees.update', $this->employee), $payload)
        ->assertRedirect();

    expect($this->employee->fresh()->phone)->toBe('+995 555 12 34 56');
});
