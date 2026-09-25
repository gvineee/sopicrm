<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('projects');

/**
 * Audit A24: „„კლიენტი" არსებობს, მაგრამ არჩევანი ცარიელია და მთავარ მენიუში
 * კლიენტების მართვის გზა არ ჩანს."
 *
 * The cause was deeper than a missing menu item. There was no controller and
 * no route, and `projects.clients.manage` — the permission ClientPolicy
 * checks for create/update — had never been created by any seeder, so that
 * method could not return true for any user in any organization. The dropdown
 * was not merely empty; nothing in the product could ever have filled it.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->owner->assignRole('owner');

    $this->manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->manager->assignRole('project_manager');

    $this->worker = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->worker->assignRole('employee');
});

test('the permission the client policy checks actually exists and is granted', function () {
    // The policy referenced a permission no seeder created. Nobody held it,
    // so nobody could add a client, which is why the dropdown stayed empty.
    expect($this->owner->can('create', Client::class))->toBeTrue()
        ->and($this->manager->can('create', Client::class))->toBeTrue()
        ->and($this->worker->can('create', Client::class))->toBeFalse();
});

test('a client can be created, appears in the list, and becomes selectable on a project', function () {
    $this->actingAs($this->manager)
        ->post(route('clients.store'), [
            'name' => 'შპს მშენებელი',
            'contact_person' => 'ნინო ქართველიშვილი',
            'phone' => '+995 555 00 00 00',
            'email' => 'nino@example.test',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('clients.index'));

    CurrentOrganization::set($this->organization->id);

    $client = Client::query()->where('name', 'შპს მშენებელი')->sole();

    expect($client->contact_info['contact_person'])->toBe('ნინო ქართველიშვილი')
        ->and($client->contact_info['phone'])->toBe('+995 555 00 00 00')
        // Empty fields are dropped rather than stored as blank strings.
        ->and($client->contact_info)->not->toHaveKey('note');

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->manager)
        ->get(route('clients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Clients/Index')
            ->where('clients.0.name', 'შპს მშენებელი')
            ->where('can.manage', true));

    CurrentOrganization::set($this->organization->id);

    // The point of the whole screen: the project form's dropdown is no longer
    // empty. Asserted as the owner, since creating a project is an
    // owner-level permission in this codebase.
    $this->actingAs($this->owner)
        ->get(route('projects.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clients.0.id', $client->id));
});

test('a client can be corrected afterwards, and the change is audited', function () {
    $client = Client::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'ძველი დასახელება',
    ]);

    $this->actingAs($this->owner)
        ->put(route('clients.update', $client), ['name' => 'ახალი დასახელება', 'phone' => '+995 599 11 11 11'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('clients.index'));

    CurrentOrganization::set($this->organization->id);

    expect($client->refresh()->name)->toBe('ახალი დასახელება');

    $event = AuditEvent::query()->where('action', 'projects.client.updated')->sole();

    expect($event->before['name'])->toBe('ძველი დასახელება')
        ->and($event->after['name'])->toBe('ახალი დასახელება');
});

test('saving a client without renaming it does not collide with itself', function () {
    $client = Client::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'იგივე დასახელება',
    ]);

    // The uniqueness rule has to ignore the row being edited, or correcting a
    // phone number would fail against the client's own name.
    $this->actingAs($this->owner)
        ->put(route('clients.update', $client), ['name' => 'იგივე დასახელება', 'phone' => '+995 577 22 22 22'])
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    expect($client->refresh()->contact_info['phone'])->toBe('+995 577 22 22 22');
});

test('two clients in one organization cannot share a name, but two organizations can', function () {
    Client::factory()->create(['organization_id' => $this->organization->id, 'name' => 'ერთი სახელი']);
    Client::factory()->create(['organization_id' => $this->otherOrganization->id, 'name' => 'ერთი სახელი']);

    CurrentOrganization::set($this->organization->id);

    // Two identically named clients are indistinguishable in the dropdown,
    // which is the only place anyone ever picks one.
    $this->actingAs($this->owner)
        ->post(route('clients.store'), ['name' => 'ერთი სახელი'])
        ->assertSessionHasErrors('name');

    CurrentOrganization::set($this->organization->id);
    expect(Client::query()->where('name', 'ერთი სახელი')->count())->toBe(1);
});

test('the list shows how many projects point at each client', function () {
    $client = Client::factory()->create(['organization_id' => $this->organization->id]);
    Project::factory()->count(2)->create([
        'organization_id' => $this->organization->id,
        'client_id' => $client->id,
        'manager_user_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)
        ->get(route('clients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clients.0.project_count', 2));
});

test('someone without the permission can neither add nor change a client', function () {
    $client = Client::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->worker)
        ->post(route('clients.store'), ['name' => 'უნებართვო'])
        ->assertForbidden();

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->worker)
        ->put(route('clients.update', $client), ['name' => 'უნებართვო'])
        ->assertForbidden();

    CurrentOrganization::set($this->organization->id);
    expect(Client::query()->where('name', 'უნებართვო')->exists())->toBeFalse();
});
