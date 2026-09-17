<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Actions\AcceptEmployeeInviteAction;
use App\Domain\Employees\Actions\IssueEmployeeInviteAction;
use App\Domain\Employees\Actions\SetTeamMembershipAction;
use App\Domain\Employees\Exceptions\InvalidEmployeeInviteException;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Employees\Models\TeamMembership;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('employees');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
    $this->actor = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
});

test('an invite stores only a hash and can be accepted once', function () {
    ['invite' => $invite, 'token' => $token] = app(IssueEmployeeInviteAction::class)->execute($this->employee, $this->actor);

    expect($invite->token_hash)->toBe(hash('sha256', $token))
        ->and($invite->getAttributes())->not->toHaveKey('token');

    $user = app(AcceptEmployeeInviteAction::class)->execute($token, [
        'name' => 'Nino Builder',
        'email' => 'nino@example.test',
        'password' => 'strong-password',
    ]);

    expect($invite->refresh()->status)->toBe('accepted')
        ->and($this->employee->refresh()->user_id)->toBe($user->id)
        ->and($user->hasRole('employee'))->toBeTrue();

    expect(fn () => app(AcceptEmployeeInviteAction::class)->execute($token, [
        'name' => 'Replay',
        'email' => 'replay@example.test',
        'password' => 'strong-password',
    ]))->toThrow(InvalidEmployeeInviteException::class);
});

test('moving an employee ends the previous active team membership', function () {
    $firstTeam = Team::factory()->create(['organization_id' => $this->organization->id]);
    $secondTeam = Team::factory()->create(['organization_id' => $this->organization->id]);
    $action = app(SetTeamMembershipAction::class);

    $firstMembership = $action->execute($this->employee, $firstTeam, '2026-01-01', $this->actor);
    $secondMembership = $action->execute($this->employee, $secondTeam, '2026-09-17', $this->actor);

    expect($firstMembership?->refresh()->ended_at)->not->toBeNull()
        ->and($secondMembership)->not->toBeNull()
        ->and(TeamMembership::query()->active()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and($this->employee->refresh()->team_id)->toBe($secondTeam->id);
});
