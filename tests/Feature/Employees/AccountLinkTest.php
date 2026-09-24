<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Actions\IssueEmployeeInviteAction;
use App\Domain\Employees\Actions\LinkEmployeeToUserAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

pest()->group('employees');

/**
 * Audit A11. „ჩემი დღე" and „ჩემი პროფილი" showed an unlinked account a dead
 * end, and the reason was structural rather than cosmetic: the only code path
 * that could ever set `employees.user_id` was accepting an invite, which
 * CREATES a user. Someone who already had an account — most obviously
 * whoever set the organization up — could never be connected to their own
 * employee record by any route at all.
 *
 * These tests cover the missing path and, just as importantly, the identity
 * rules it must not break: one employee has at most one account, one account
 * belongs to at most one employee, and neither crosses an organization.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->hr = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->hr->assignRole('hr');

    $this->account = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'name' => 'ნინო მშენებელი',
    ]);

    $this->employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => null,
    ]);
});

test('an existing account can be linked to an employee, and the pages that were empty start working', function () {
    // The person is invisible to their own pages beforehand — this is exactly
    // what a real user reported.
    $this->actingAs($this->account)->get(route('me.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Me/Profile')->where('employee', null));

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->hr)
        ->post(route('employees.user-link.store', $this->employee), ['user_id' => $this->account->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('employees.show', $this->employee));

    CurrentOrganization::set($this->organization->id);
    expect($this->employee->refresh()->user_id)->toBe($this->account->id);

    // The link is a decision about who may act as this person, so it is
    // audited with both sides of the change.
    $event = AuditEvent::query()->where('action', 'employees.user.linked')->sole();
    expect($event->actor_user_id)->toBe($this->hr->id)
        ->and($event->target_id)->toBe($this->employee->id)
        ->and($event->after['user_id'])->toBe($this->account->id);

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->account)->get(route('me.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('employee.id', $this->employee->id));

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->account)->get(route('my-day'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('hasEmployeeRecord', true));
});

test('linking revokes a still-pending invite, so one person cannot end up with two logins', function () {
    ['invite' => $invite] = app(IssueEmployeeInviteAction::class)->execute($this->employee, $this->hr);

    expect($invite->refresh()->status)->toBe('pending');

    app(LinkEmployeeToUserAction::class)->execute($this->employee, $this->account, $this->hr);

    // An invite is an instruction to create a SECOND account for this person.
    // Leaving it live after linking an existing one is how spec section 5's
    // "no shared / no duplicate accounts" rule quietly breaks.
    expect($invite->refresh()->status)->toBe('revoked')
        ->and($invite->revoked_by_user_id)->toBe($this->hr->id);
});

test('one account cannot answer for two employee records', function () {
    app(LinkEmployeeToUserAction::class)->execute($this->employee, $this->account, $this->hr);

    $secondEmployee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => null,
    ]);

    // This is not a tidiness rule. The two-person acceptance check compares
    // User ids AND Employee ids to decide whether a reviewer is the same
    // human as the performer; one account standing for two employee records
    // would let a single person pass as two independent people.
    expect(fn () => app(LinkEmployeeToUserAction::class)->execute($secondEmployee, $this->account, $this->hr))
        ->toThrow(RuntimeException::class);

    expect($secondEmployee->refresh()->user_id)->toBeNull();
});

test('an employee who already has a login, and a terminated employee, are both refused', function () {
    app(LinkEmployeeToUserAction::class)->execute($this->employee, $this->account, $this->hr);

    $another = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    expect(fn () => app(LinkEmployeeToUserAction::class)->execute($this->employee, $another, $this->hr))
        ->toThrow(RuntimeException::class);

    expect($this->employee->refresh()->user_id)->toBe($this->account->id);

    $terminated = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => null,
        'status' => 'terminated',
    ]);

    expect(fn () => app(LinkEmployeeToUserAction::class)->execute($terminated, $another, $this->hr))
        ->toThrow(RuntimeException::class);

    expect($terminated->refresh()->user_id)->toBeNull();
});

test('a system account is never linkable, and neither is an account from another organization', function () {
    $systemAccount = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $systemAccount->is_system_account = true;
    $systemAccount->save();

    expect(fn () => app(LinkEmployeeToUserAction::class)->execute($this->employee, $systemAccount, $this->hr))
        ->toThrow(RuntimeException::class);

    $outsider = User::factory()->create([
        'organization_id' => $this->otherOrganization->id,
        'current_organization_id' => $this->otherOrganization->id,
    ]);

    // Refused at validation, because `Rule::exists` runs beneath the tenant
    // scope and so has to carry the organization predicate itself.
    $this->actingAs($this->hr)
        ->post(route('employees.user-link.store', $this->employee), ['user_id' => $outsider->id])
        ->assertSessionHasErrors('user_id');

    CurrentOrganization::set($this->organization->id);
    expect($this->employee->refresh()->user_id)->toBeNull();
});

test('linking is gated by invite-management authority, not by merely being able to read the roster', function () {
    $viewer = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $viewer->assignRole('foreman');

    $this->actingAs($viewer)
        ->post(route('employees.user-link.store', $this->employee), ['user_id' => $this->account->id])
        ->assertForbidden();

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($viewer)
        ->getJson(route('employees.user-link.options', $this->employee))
        ->assertForbidden();

    CurrentOrganization::set($this->organization->id);
    expect($this->employee->refresh()->user_id)->toBeNull();
});

test('the picker only offers accounts no employee record has claimed', function () {
    $taken = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $taken->id,
    ]);

    $outsider = User::factory()->create([
        'organization_id' => $this->otherOrganization->id,
        'current_organization_id' => $this->otherOrganization->id,
    ]);

    $response = $this->actingAs($this->hr)
        ->getJson(route('employees.user-link.options', $this->employee))
        ->assertOk();

    $ids = collect($response->json('options'))->pluck('id')->all();

    expect($ids)->toContain($this->account->id)
        ->and($ids)->not->toContain($taken->id)
        ->and($ids)->not->toContain($outsider->id);
});
