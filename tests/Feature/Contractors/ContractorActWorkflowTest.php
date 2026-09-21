<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Contractors\Actions\AcceptContractorAct;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Contractors\Models\ContractorActAcceptance;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Contractors\Services\ContractorBalanceService;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Comment;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

pest()->group('contractors');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->owner->assignRole('owner');

    $this->project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => Client::factory()->create(['organization_id' => $this->organization->id])->id,
        'manager_user_id' => $this->owner->id,
    ]);

    $this->contractor = Contractor::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Test Contractor',
        'default_currency' => 'GEL',
        'is_active' => true,
    ]);

    $this->activeContract = ContractorContract::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_id' => $this->contractor->id,
        'project_id' => $this->project->id,
        'title' => 'Active contract',
        'rate_type' => 'lump_sum',
        'total_amount' => '1000.00',
        'currency' => 'GEL',
        'starts_on' => now()->toDateString(),
        'status' => 'active',
        'created_by_user_id' => $this->owner->id,
    ]);
});

test('an act cannot be submitted against a non-active contract', function () {
    $draftContract = ContractorContract::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_id' => $this->contractor->id,
        'project_id' => $this->project->id,
        'title' => 'Draft contract',
        'rate_type' => 'lump_sum',
        'currency' => 'GEL',
        'starts_on' => now()->toDateString(),
        'status' => 'draft',
        'created_by_user_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)->post(route('contractors.acts.store', $this->contractor), [
        'contract_id' => $draftContract->id,
        'project_id' => $this->project->id,
        'description' => 'Work',
    ])->assertSessionHasErrors('contract_id');

    CurrentOrganization::set($this->organization->id);
    expect(ContractorAct::query()->count())->toBe(0);
});

test('accepting the same act twice is impossible at both the action and database layer', function () {
    $act = ContractorAct::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_id' => $this->contractor->id,
        'contract_id' => $this->activeContract->id,
        'project_id' => $this->project->id,
        'submitted_by_user_id' => $this->owner->id,
        'evidence_attachment_ids' => [],
        'submitted_at' => now(),
        'status' => 'pending_review',
    ]);

    $action = app(AcceptContractorAct::class);
    $action->execute($act, $this->owner, null, '100.00', null);

    expect(fn () => $action->execute($act->fresh(), $this->owner, null, '100.00', null))
        ->toThrow(ValidationException::class);

    // Bypass the Action's own pre-check entirely to prove the DB constraint
    // itself — not just application code — makes a duplicate impossible.
    expect(fn () => ContractorActAcceptance::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_act_id' => $act->id,
        'accepted_by_user_id' => $this->owner->id,
        'accepted_amount' => '50.00',
        'accepted_at' => now(),
    ]))->toThrow(QueryException::class);

    CurrentOrganization::set($this->organization->id);
    expect(ContractorActAcceptance::query()->where('contractor_act_id', $act->id)->count())->toBe(1);
});

test('returning an act posts a comment and the act can never be accepted afterwards', function () {
    $act = ContractorAct::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_id' => $this->contractor->id,
        'contract_id' => $this->activeContract->id,
        'project_id' => $this->project->id,
        'submitted_by_user_id' => $this->owner->id,
        'evidence_attachment_ids' => [],
        'submitted_at' => now(),
        'status' => 'pending_review',
    ]);

    $this->actingAs($this->owner)->post(route('contractors.acts.return', [$this->contractor, $act]), [
        'reason' => 'ფოტო არასაკმარისია',
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($act->refresh()->status)->toBe('returned')
        ->and(Comment::query()->where('commentable_type', ContractorAct::class)->where('commentable_id', $act->id)->exists())->toBeTrue();

    $this->actingAs($this->owner)->post(route('contractors.acts.accept', [$this->contractor, $act]), [
        'accepted_amount' => '100.00',
    ])->assertSessionHasErrors('status');
});

test('a payment exceeding the outstanding balance is rejected and does not double-subtract across payments', function () {
    $act = ContractorAct::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_id' => $this->contractor->id,
        'contract_id' => $this->activeContract->id,
        'project_id' => $this->project->id,
        'submitted_by_user_id' => $this->owner->id,
        'evidence_attachment_ids' => [],
        'submitted_at' => now(),
        'status' => 'pending_review',
    ]);
    app(AcceptContractorAct::class)->execute($act, $this->owner, null, '500.00', null);

    $this->actingAs($this->owner)->post(route('contractors.contracts.payments.store', [$this->contractor, $this->activeContract]), [
        'amount' => '999.00',
        'currency' => 'GEL',
        'paid_at' => now()->toDateString(),
        'request_id' => (string) Str::uuid(),
    ])->assertSessionHasErrors('amount');

    $this->actingAs($this->owner)->post(route('contractors.contracts.payments.store', [$this->contractor, $this->activeContract]), [
        'amount' => '300.00',
        'currency' => 'GEL',
        'paid_at' => now()->toDateString(),
        'request_id' => (string) Str::uuid(),
    ])->assertRedirect();

    $this->actingAs($this->owner)->post(route('contractors.contracts.payments.store', [$this->contractor, $this->activeContract]), [
        'amount' => '150.00',
        'currency' => 'GEL',
        'paid_at' => now()->toDateString(),
        'request_id' => (string) Str::uuid(),
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $balance = app(ContractorBalanceService::class);
    expect($balance->outstanding($this->activeContract->fresh()))->toBe(50.0);
});
