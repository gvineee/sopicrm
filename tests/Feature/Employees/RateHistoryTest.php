<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Actions\CreateRateHistoryAction;
use App\Domain\Employees\Exceptions\NoApplicableRateException;
use App\Domain\Employees\Exceptions\OverlappingRateException;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Services\RateResolutionService;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;

pest()->group('employees');

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    $this->actor = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
});

test('overlapping rates at the same scope and type are rejected', function () {
    $action = app(CreateRateHistoryAction::class);

    $action->execute($this->employee, [
        'rate_type' => 'hourly',
        'amount' => '20.00',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-06-30',
        'change_reason' => 'Initial rate',
    ], $this->actor);

    expect(fn () => $action->execute($this->employee, [
        'rate_type' => 'hourly',
        'amount' => '25.00',
        'effective_from' => '2026-06-01',
        'effective_to' => null,
        'change_reason' => 'Overlapping rate',
    ], $this->actor))->toThrow(OverlappingRateException::class);
});

test('project rate takes priority over the base rate', function () {
    $project = Project::factory()->create(['organization_id' => $this->organization->id]);
    $action = app(CreateRateHistoryAction::class);

    $baseRate = $action->execute($this->employee, [
        'rate_type' => 'hourly',
        'amount' => '20.00',
        'effective_from' => '2026-01-01',
        'change_reason' => 'Base rate',
    ], $this->actor);
    $projectRate = $action->execute($this->employee, [
        'rate_type' => 'hourly',
        'amount' => '30.00',
        'effective_from' => '2026-01-01',
        'project_id' => $project->id,
        'change_reason' => 'Project premium',
    ], $this->actor);

    $service = app(RateResolutionService::class);

    expect($service->resolve($this->employee->id, '2026-09-17', $project->id, 'hourly')->is($projectRate))->toBeTrue()
        ->and($service->resolve($this->employee->id, '2026-09-17', null, 'hourly')->is($baseRate))->toBeTrue();
});

test('accrual is blocked when no applicable rate exists', function () {
    app(RateResolutionService::class)->resolve($this->employee->id, '2026-09-17', null, 'hourly');
})->throws(NoApplicableRateException::class);
