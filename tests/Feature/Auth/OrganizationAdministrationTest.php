<?php

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Auth\Actions\PurgeOrganizationAction;
use App\Domain\Auth\Models\Membership;
use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Auth\Support\OrganizationPurgePlanner;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\CompanyMembership;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCheckpoint;
use App\Domain\Devices\Models\Door;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Models\Zone;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Employment;
use App\Domain\Employees\Models\Position;
use App\Domain\Employees\Models\Team;
use App\Domain\Employees\Models\TeamMembership;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Models\Notification;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Shared\Services\NavigationService;
use App\Domain\Tasks\Models\Comment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

pest()->group('auth');

/**
 * Platform organization administration (App\Http\Controllers\Platform\
 * OrganizationController) and the hard purge
 * (App\Domain\Auth\Actions\PurgeOrganizationAction).
 *
 * What this cannot cover: the suite runs on SQLite, so PostgreSQL's
 * row-level security during the purge (the action switches
 * app.current_org_id to the purged organization) is not exercised here. The
 * delete ORDER is built from the same introspection on both drivers, and was
 * checked read-only against the live PostgreSQL schema when this was written.
 */
beforeEach(function () {
    // These pages are new; the suite must not depend on public/build having
    // been rebuilt (that build is what the live site serves).
    $this->withoutVite();
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->adminOrganization = Organization::factory()->create(['name' => 'Platform Home']);
    $this->admin = User::factory()->create([
        'organization_id' => $this->adminOrganization->id,
        'current_organization_id' => $this->adminOrganization->id,
    ]);
    $this->admin->forceFill(['is_platform_admin' => true])->save();

    $this->tenantOwner = User::factory()->create([
        'organization_id' => $this->adminOrganization->id,
        'current_organization_id' => $this->adminOrganization->id,
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->adminOrganization->id);
    $this->tenantOwner->assignRole('owner');
});

/**
 * One of everything the purge has to get right: rows across several domains,
 * references between tenant tables, the users <-> attachments and
 * employees <-> teams SET NULL cycles, spatie role rows, a passkey, a
 * session and API tokens that hang off users rather than organization_id.
 *
 * @return array{owner: User, member: User}
 */
function seedRepresentativeOrganization(Organization $organization): array
{
    $orgId = $organization->id;
    CurrentOrganization::set($orgId);
    app(PermissionRegistrar::class)->setPermissionsTeamId($orgId);

    $company = Company::factory()->create(['organization_id' => $orgId]);
    $owner = User::factory()->create(['organization_id' => $orgId, 'current_organization_id' => $orgId, 'current_company_id' => $company->id]);
    $member = User::factory()->create(['organization_id' => $orgId, 'current_organization_id' => $orgId, 'current_company_id' => $company->id]);
    $owner->assignRole('owner');
    $member->givePermissionTo('auth.users.view');

    Membership::factory()->create(['organization_id' => $orgId, 'user_id' => $owner->id, 'is_primary' => true]);
    CompanyMembership::factory()->create(['organization_id' => $orgId, 'company_id' => $company->id, 'user_id' => $owner->id, 'is_primary' => true]);

    $site = Site::factory()->create(['organization_id' => $orgId, 'company_id' => $company->id]);
    $zone = Zone::factory()->create(['organization_id' => $orgId, 'site_id' => $site->id]);
    $device = Device::factory()->create(['organization_id' => $orgId, 'site_id' => $site->id]);
    Door::factory()->create(['organization_id' => $orgId, 'site_id' => $site->id, 'zone_id' => $zone->id, 'device_id' => $device->id]);
    DeviceCheckpoint::factory()->create(['organization_id' => $orgId, 'device_id' => $device->id]);

    $position = Position::factory()->create(['organization_id' => $orgId]);
    $employee = Employee::factory()->create([
        'organization_id' => $orgId,
        'company_id' => $company->id,
        'user_id' => $member->id,
        'position_id' => $position->id,
    ]);
    Employment::factory()->create(['organization_id' => $orgId, 'employee_id' => $employee->id]);
    $team = Team::factory()->create(['organization_id' => $orgId]);
    TeamMembership::factory()->create(['organization_id' => $orgId, 'team_id' => $team->id, 'employee_id' => $employee->id]);
    DB::table('teams')->where('id', $team->id)->update(['foreman_employee_id' => $employee->id]);
    DB::table('employees')->where('id', $employee->id)->update(['team_id' => $team->id]);

    $credential = Credential::factory()->create(['organization_id' => $orgId]);
    CredentialAssignment::factory()->create(['organization_id' => $orgId, 'credential_id' => $credential->id, 'employee_id' => $employee->id]);
    $event = RawAccessEvent::factory()->create(['organization_id' => $orgId, 'device_id' => $device->id, 'credential_id' => $credential->id]);
    $session = AttendanceSession::factory()->create([
        'organization_id' => $orgId,
        'employee_id' => $employee->id,
        'site_id' => $site->id,
        'clock_in_event_id' => $event->id,
    ]);
    AttendanceAnomaly::factory()->create([
        'organization_id' => $orgId,
        'employee_id' => $employee->id,
        'raw_access_event_id' => $event->id,
        'attendance_session_id' => $session->id,
    ]);

    $project = Project::factory()->create(['organization_id' => $orgId, 'company_id' => $company->id, 'site_id' => $site->id, 'manager_user_id' => $owner->id]);
    ProjectMembership::factory()->create(['organization_id' => $orgId, 'project_id' => $project->id, 'user_id' => $member->id]);
    $task = Task::factory()->create(['organization_id' => $orgId, 'project_id' => $project->id, 'accountable_owner_employee_id' => $employee->id]);
    TaskAssignee::factory()->create(['organization_id' => $orgId, 'task_id' => $task->id, 'employee_id' => $employee->id]);
    Comment::factory()->create(['organization_id' => $orgId, 'commentable_id' => $task->id, 'author_user_id' => $member->id]);

    $attachment = Attachment::factory()->create(['organization_id' => $orgId, 'uploaded_by_user_id' => $owner->id]);
    DB::table('users')->where('id', $member->id)->update(['photo_attachment_id' => $attachment->id]);

    $payPeriod = PayPeriod::factory()->create(['organization_id' => $orgId]);
    Timesheet::factory()->create(['organization_id' => $orgId, 'employee_id' => $employee->id, 'pay_period_id' => $payPeriod->id]);

    Notification::factory()->create(['organization_id' => $orgId, 'recipient_user_id' => $member->id]);
    AuditEvent::factory()->create(['organization_id' => $orgId, 'actor_user_id' => $owner->id]);

    $owner->createToken('owner-token');
    $organization->createToken('machine-token')->accessToken->forceFill(['organization_id' => $orgId])->save();

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $owner->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
    DB::table('passkeys')->insert([
        'user_id' => $owner->id,
        'name' => 'key',
        'credential_id' => Str::random(20),
        'credential' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CurrentOrganization::clear();

    return ['owner' => $owner, 'member' => $member];
}

/**
 * Row count per table for one organization: every organization_id table,
 * plus the rows that belong to it only through its users.
 *
 * @return array<string, int>
 */
function organizationFootprint(string $organizationId): array
{
    $footprint = [];
    $userIds = DB::table('users')->where('organization_id', $organizationId)->pluck('id')->all();

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (Schema::hasColumn($table, 'organization_id')) {
            $footprint[$table] = DB::table($table)->where('organization_id', $organizationId)->count();
        }
    }

    $footprint['sessions'] = DB::table('sessions')->whereIn('user_id', $userIds)->count();
    $footprint['passkeys'] = DB::table('passkeys')->whereIn('user_id', $userIds)->count();
    $footprint['user_tokens'] = DB::table('personal_access_tokens')
        ->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->count();
    $footprint['organizations'] = DB::table('organizations')->where('id', $organizationId)->count();

    return $footprint;
}

test('a user who is not a platform admin cannot reach any organization route, even an owner', function () {
    $organization = Organization::factory()->create();

    $this->actingAs($this->tenantOwner)->get(route('platform.organizations.index'))->assertForbidden();
    $this->actingAs($this->tenantOwner)->get(route('platform.organizations.create'))->assertForbidden();
    $this->actingAs($this->tenantOwner)->post(route('platform.organizations.store'), [
        'name' => 'Nope', 'owner_name' => 'X', 'owner_email' => 'x@example.com',
        'owner_password' => 'Secret-pass-123', 'owner_password_confirmation' => 'Secret-pass-123',
    ])->assertForbidden();
    $this->actingAs($this->tenantOwner)->put(route('platform.organizations.update', $organization), ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($this->tenantOwner)->delete(route('platform.organizations.destroy', $organization), [
        'confirmation_name' => $organization->name,
    ])->assertForbidden();

    expect(Organization::query()->whereKey($organization->id)->value('name'))->toBe($organization->name)
        ->and(Organization::query()->where('name', 'Nope')->exists())->toBeFalse();
});

test('the organizations nav entry is shown to a platform admin and to nobody else', function () {
    $labelsFor = fn (User $user): array => collect(app(NavigationService::class)->groupsForUser($user))
        ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
        ->all();

    expect($labelsFor($this->admin))->toContain('ორგანიზაციები')
        ->and($labelsFor($this->tenantOwner))->not->toContain('ორგანიზაციები')
        // The owner still gets the rest of the admin section — the check
        // above is not passing because nav filtering is broken for them.
        ->and($labelsFor($this->tenantOwner))->toContain('როლები');
});

test('the list shows every organization with its counts and marks the admin\'s own as current', function () {
    $other = Organization::factory()->create(['name' => 'Other Org']);
    seedRepresentativeOrganization($other);

    $this->actingAs($this->admin)
        ->get(route('platform.organizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Platform/Organizations/Index')
            ->where('organizations', function ($organizations) use ($other) {
                $rows = collect($organizations)->keyBy('id');

                return $rows->count() === 2
                    && $rows[$this->adminOrganization->id]['is_current'] === true
                    && $rows[$this->adminOrganization->id]['users_count'] === 2
                    && $rows[$other->id]['is_current'] === false
                    && $rows[$other->id]['users_count'] === 2
                    && $rows[$other->id]['employees_count'] === 1
                    && $rows[$other->id]['projects_count'] === 1;
            }));
});

test('creating an organization makes a working tenant whose owner has the owner role\'s permissions', function () {
    $this->actingAs($this->admin)
        ->post(route('platform.organizations.store'), [
            'name' => 'New Builder LLC',
            'legal_name' => 'შპს ახალი მშენებელი',
            'owner_name' => 'Nino Owner',
            'owner_email' => 'nino@example.com',
            'owner_password' => 'Secret-pass-123',
            'owner_password_confirmation' => 'Secret-pass-123',
        ])
        ->assertRedirect(route('platform.organizations.index'))
        ->assertSessionHasNoErrors();

    $organization = Organization::query()->where('name', 'New Builder LLC')->firstOrFail();
    $owner = User::query()->where('email', 'nino@example.com')->firstOrFail();

    expect($organization->legal_name)->toBe('შპს ახალი მშენებელი')
        ->and($organization->default_currency)->toBe('GEL')
        ->and($organization->default_timezone)->toBe('Asia/Tbilisi')
        ->and($owner->organization_id)->toBe($organization->id)
        ->and($owner->current_organization_id)->toBe($organization->id)
        ->and($owner->is_platform_admin)->toBeFalse();

    $company = DB::table('companies')->where('organization_id', $organization->id)->first();
    expect($company->code)->toBe('DEFAULT')
        ->and($owner->current_company_id)->toBe($company->id)
        ->and(DB::table('company_memberships')->where('user_id', $owner->id)->where('is_primary', true)->exists())->toBeTrue();

    // The role is scoped to the NEW organization, not the admin's.
    app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
    $owner->unsetRelation('roles')->unsetRelation('permissions');
    expect($owner->hasRole('owner'))->toBeTrue()
        ->and($owner->can('auth.users.view'))->toBeTrue()
        ->and($owner->can('companies.manage'))->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->adminOrganization->id);
    $owner->unsetRelation('roles')->unsetRelation('permissions');
    expect($owner->hasRole('owner'))->toBeFalse();

    // And it works in a real request as that owner, through the ordinary
    // tenancy middleware.
    $this->actingAs($owner->fresh())
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('users', fn ($users) => collect($users)->pluck('email')->all() === ['nino@example.com']));

    $this->actingAs($owner->fresh())->get(route('platform.organizations.index'))->assertForbidden();

    $audit = AuditEvent::withoutTenantScope()->where('action', 'platform.organization.created')->firstOrFail();
    expect($audit->organization_id)->toBe($this->adminOrganization->id)
        ->and($audit->actor_user_id)->toBe($this->admin->id)
        ->and($audit->target_id)->toBe($organization->id);
});

test('creating an organization rejects an owner e-mail already used in any organization', function () {
    $this->actingAs($this->admin)
        ->post(route('platform.organizations.store'), [
            'name' => 'Duplicate Email Org',
            'owner_name' => 'Someone',
            'owner_email' => $this->tenantOwner->email,
            'owner_password' => 'Secret-pass-123',
            'owner_password_confirmation' => 'Secret-pass-123',
        ])
        ->assertSessionHasErrors('owner_email');

    expect(Organization::query()->where('name', 'Duplicate Email Org')->exists())->toBeFalse();
});

test('a platform admin can rename an organization, audited', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);

    $this->actingAs($this->admin)
        ->put(route('platform.organizations.update', $organization), ['name' => 'New Name', 'legal_name' => 'შპს ახალი'])
        ->assertRedirect(route('platform.organizations.index'));

    expect($organization->fresh()->name)->toBe('New Name')
        ->and($organization->fresh()->legal_name)->toBe('შპს ახალი');

    $audit = AuditEvent::withoutTenantScope()->where('action', 'platform.organization.updated')->firstOrFail();
    expect($audit->organization_id)->toBe($this->adminOrganization->id)
        ->and($audit->before['name'])->toBe('Old Name')
        ->and($audit->after['name'])->toBe('New Name');
});

test('a platform admin cannot delete the organization they belong to', function () {
    $before = organizationFootprint($this->adminOrganization->id);

    $this->actingAs($this->admin)
        ->delete(route('platform.organizations.destroy', $this->adminOrganization), [
            'confirmation_name' => $this->adminOrganization->name,
        ])
        ->assertSessionHasErrors('organization');

    expect(organizationFootprint($this->adminOrganization->id))->toBe($before);

    // The Action refuses on its own too, not only through the controller.
    expect(fn () => app(PurgeOrganizationAction::class)->execute($this->adminOrganization, $this->admin, $this->adminOrganization->name))
        ->toThrow(ValidationException::class);
    expect(Organization::query()->whereKey($this->adminOrganization->id)->exists())->toBeTrue();
});

test('a wrong confirmation name is rejected by the server and deletes nothing', function () {
    $target = Organization::factory()->create(['name' => 'Target Org']);
    seedRepresentativeOrganization($target);
    $before = organizationFootprint($target->id);

    // (Surrounding whitespace is trimmed by the TrimStrings middleware before
    // validation, so "Target Org " counts as the exact name — deliberately
    // not listed here.)
    foreach (['', 'target org', 'Target', 'Target  Org', 'Other'] as $typed) {
        $this->actingAs($this->admin)
            ->delete(route('platform.organizations.destroy', $target), ['confirmation_name' => $typed])
            ->assertSessionHasErrors('confirmation_name');
    }

    expect(organizationFootprint($target->id))->toBe($before);

    expect(fn () => app(PurgeOrganizationAction::class)->execute($target, $this->admin, 'target org'))
        ->toThrow(ValidationException::class);
    expect(organizationFootprint($target->id))->toBe($before);
});

test('purging an organization removes every one of its rows and leaves an identical second organization intact', function () {
    $target = Organization::factory()->create(['name' => 'Doomed Org']);
    $survivor = Organization::factory()->create(['name' => 'Surviving Org']);
    ['owner' => $targetOwner] = seedRepresentativeOrganization($target);
    seedRepresentativeOrganization($survivor);

    $targetBefore = organizationFootprint($target->id);
    $survivorBefore = organizationFootprint($survivor->id);
    $adminBefore = organizationFootprint($this->adminOrganization->id);

    // Proves the fixture really spans the domains the purge must handle —
    // otherwise "all zero afterwards" would prove nothing.
    foreach (['users', 'employees', 'teams', 'projects', 'tasks', 'comments', 'devices', 'doors', 'raw_access_events',
        'attendance_sessions', 'attendance_anomalies', 'credential_assignments', 'timesheets', 'attachments',
        'model_has_roles', 'model_has_permissions', 'personal_access_tokens', 'audit_events', 'companies', 'sessions', 'passkeys',
        'user_tokens'] as $table) {
        expect($targetBefore[$table])->toBeGreaterThan(0, "fixture has no {$table} rows");
    }
    expect($targetBefore)->toBe($survivorBefore);

    $this->actingAs($this->admin)
        ->delete(route('platform.organizations.destroy', $target), ['confirmation_name' => 'Doomed Org'])
        ->assertRedirect(route('platform.organizations.index'))
        ->assertSessionHasNoErrors();

    $targetAfter = organizationFootprint($target->id);
    expect(array_filter($targetAfter))->toBe([])
        ->and(DB::table('personal_access_tokens')->where('tokenable_type', Organization::class)->where('tokenable_id', $target->id)->count())->toBe(0)
        ->and(DB::table('users')->where('id', $targetOwner->id)->exists())->toBeFalse();

    expect(organizationFootprint($survivor->id))->toBe($survivorBefore);

    // The admin's organization gained exactly one row: the purge's own audit record.
    $adminAfter = organizationFootprint($this->adminOrganization->id);
    expect($adminAfter['audit_events'])->toBe($adminBefore['audit_events'] + 1);
    unset($adminAfter['audit_events'], $adminBefore['audit_events']);
    expect($adminAfter)->toBe($adminBefore);

    $audit = AuditEvent::withoutTenantScope()->where('action', 'platform.organization.purged')->firstOrFail();
    expect($audit->organization_id)->toBe($this->adminOrganization->id)
        ->and($audit->actor_user_id)->toBe($this->admin->id)
        ->and($audit->target_id)->toBe($target->id)
        ->and($audit->before['name'])->toBe('Doomed Org')
        ->and($audit->after['rows_deleted']['employees'])->toBe(1)
        ->and($audit->after['rows_deleted']['users'])->toBe(2)
        ->and($audit->after['rows_deleted']['organizations'])->toBe(1)
        ->and($audit->after['rows_deleted']['sessions'])->toBe(1)
        ->and($audit->after['rows_deleted']['passkeys'])->toBe(1);
});

test('a user of another organization who had switched into the purged one is sent home, not deleted', function () {
    $target = Organization::factory()->create(['name' => 'Visited Org']);
    $visitor = User::factory()->create([
        'organization_id' => $this->adminOrganization->id,
        'current_organization_id' => $target->id,
    ]);

    app(PurgeOrganizationAction::class)->execute($target, $this->admin, 'Visited Org');

    expect($visitor->fresh()->current_organization_id)->toBe($this->adminOrganization->id)
        ->and(Organization::query()->whereKey($target->id)->exists())->toBeFalse();
});

test('the purge plan deletes every referencing table before the table it references', function () {
    $plan = app(OrganizationPurgePlanner::class)->plan();
    $position = array_flip($plan['order']);

    foreach ($plan['order'] as $table) {
        foreach (Schema::getForeignKeys($table) as $fk) {
            $parent = $fk['foreign_table'];
            $relaxed = $fk['on_delete'] === 'set null' && count($fk['columns']) === 1;

            if ($parent === $table || $relaxed || ! isset($position[$parent])) {
                continue;
            }

            expect($position[$table])->toBeLessThan($position[$parent], "{$table} must be deleted before {$parent}");
        }
    }

    expect($plan['order'])->toContain('passkeys', 'role_has_permissions', 'users', 'employees', 'companies')
        ->not->toContain('organizations', 'migrations', 'permissions');
});
