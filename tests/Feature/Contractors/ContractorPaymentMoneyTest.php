<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Contractors\Actions\RecordContractorPaymentAction;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Contractors\Models\ContractorActAcceptance;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Contractors\Models\ContractorPayment;
use App\Domain\Contractors\Services\ContractorBalanceService;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * MONEY-01 (docs/claude-platform-completion-2026-09-21.md, audit findings
 * D1/D2) — Contractors side of the same fix already applied to
 * App\Domain\Payroll\Actions\RecordPaymentAction.
 */
pest()->group('contractors');

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);

    $this->owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

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

    $this->contract = ContractorContract::query()->create([
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

    $act = ContractorAct::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_id' => $this->contractor->id,
        'contract_id' => $this->contract->id,
        'project_id' => $this->project->id,
        'submitted_by_user_id' => $this->owner->id,
        'evidence_attachment_ids' => [],
        'submitted_at' => now(),
        'status' => 'pending_review',
    ]);
    ContractorActAcceptance::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_act_id' => $act->id,
        'accepted_by_user_id' => $this->owner->id,
        'accepted_amount' => '200.00',
        'accepted_at' => now(),
    ]);
    $act->update(['status' => 'accepted']);
});

test('MONEY-01/D2: a payment currency that does not match the contract currency is rejected', function () {
    expect(fn () => app(RecordContractorPaymentAction::class)->execute(
        $this->contractor, $this->contract, '50.00', 'USD', now()->toDateString(), null, null, null, $this->owner,
    ))->toThrow(ValidationException::class);

    CurrentOrganization::set($this->organization->id);
    expect(ContractorPayment::query()->count())->toBe(0);
});

test('MONEY-01/D1: an overpayment beyond the outstanding balance is rejected', function () {
    expect(fn () => app(RecordContractorPaymentAction::class)->execute(
        $this->contractor, $this->contract, '250.00', 'GEL', now()->toDateString(), null, null, null, $this->owner,
    ))->toThrow(ValidationException::class);

    CurrentOrganization::set($this->organization->id);
    expect(ContractorPayment::query()->count())->toBe(0);
});

test('MONEY-01/D1: retrying the same request_id returns the original payment instead of recording a second one', function () {
    $requestId = (string) Str::uuid();

    $first = app(RecordContractorPaymentAction::class)->execute(
        $this->contractor, $this->contract, '100.00', 'GEL', now()->toDateString(), 'cash', null, null, $this->owner, $requestId,
    );
    CurrentOrganization::set($this->organization->id);
    $second = app(RecordContractorPaymentAction::class)->execute(
        $this->contractor, $this->contract, '100.00', 'GEL', now()->toDateString(), 'cash', null, null, $this->owner, $requestId,
    );
    CurrentOrganization::set($this->organization->id);

    expect($second->id)->toBe($first->id)
        ->and(ContractorPayment::query()->count())->toBe(1)
        ->and(app(ContractorBalanceService::class)->outstanding($this->contract->fresh()))->toBe(100.0);
});
