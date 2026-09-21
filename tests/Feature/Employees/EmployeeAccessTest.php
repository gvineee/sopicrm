<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Position;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

pest()->group('employees');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
});

test('hr can open the employee module pages', function () {
    $hr = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $hr->assignRole('hr');
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($hr)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Employees/Index'));

    $this->actingAs($hr)
        ->get(route('employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Employees/Show'));
});

test('hr can manage positions and assign them to employees, filterable on the index', function () {
    $hr = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $hr->assignRole('hr');

    $this->actingAs($hr)->post(route('positions.store'), [
        'name' => 'ელექტრიკოსი',
        'is_active' => true,
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $position = Position::query()->where('name', 'ელექტრიკოსი')->sole();

    $this->actingAs($hr)
        ->get(route('positions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Employees/Positions')
            ->where('positions', fn ($positions) => collect($positions)->pluck('name')->contains('ელექტრიკოსი')));

    $matching = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'position_id' => $position->id,
    ]);
    Employee::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($hr)
        ->get(route('employees.index', ['position_id' => $position->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Employees/Index')
            ->where('employees.data', fn ($employees) => collect($employees)->pluck('id')->all() === [$matching->id]));
});

test('project managers cannot view employee rates', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $manager->assignRole('project_manager');
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $rate = RateHistory::factory()->create([
        'organization_id' => $this->organization->id,
        'employee_id' => $employee->id,
        'approved_by_user_id' => $manager->id,
    ]);

    expect($manager->can('view', $rate))->toBeFalse()
        ->and($manager->can('viewAny', [RateHistory::class, $employee->id]))->toBeFalse();
});

test('employee documents are private and policy checked', function () {
    Storage::fake('private');

    $hr = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $hr->assignRole('hr');
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($hr)->post(route('employees.documents.store', $employee), [
        'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        'caption' => 'Employment contract',
    ])->assertRedirect(route('employees.show', $employee));

    CurrentOrganization::set($this->organization->id);
    $attachment = Attachment::query()->sole();
    Storage::disk('private')->assertExists($attachment->storage_path);

    $this->actingAs($hr)
        ->get(route('employees.documents.download', [$employee, $attachment]))
        ->assertOk();

    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $manager->assignRole('project_manager');

    $this->actingAs($manager)
        ->get(route('employees.documents.download', [$employee, $attachment]))
        ->assertForbidden();
});
