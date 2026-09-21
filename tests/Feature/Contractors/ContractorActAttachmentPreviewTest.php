<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * FILES-01 (deferred remainder): before this ticket, ContractorActResource
 * exposed evidence attachment metadata with no URL at all, and no route
 * existed to actually stream a contractor act's own evidence file — a
 * reviewer had no way to open the proof before accepting/returning an act.
 * Mirrors tests/Feature/Projects/TaskAttachmentPreviewTest.php's exact
 * shape for the equivalent Task-side fix.
 */
pest()->group('contractors');

beforeEach(function () {
    Storage::fake('private');
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
        'name' => 'Evidence Contractor',
        'default_currency' => 'GEL',
        'is_active' => true,
    ]);

    $this->contract = ContractorContract::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_id' => $this->contractor->id,
        'project_id' => $this->project->id,
        'title' => 'Evidence contract',
        'rate_type' => 'lump_sum',
        'total_amount' => '1000.00',
        'currency' => 'GEL',
        'starts_on' => now()->toDateString(),
        'status' => 'active',
        'created_by_user_id' => $this->owner->id,
    ]);

    $this->attachment = Attachment::query()->create([
        'organization_id' => $this->organization->id,
        'owner_type' => Contractor::class,
        'owner_id' => $this->contractor->id,
        'disk' => 'private',
        'storage_path' => 'contractors/'.$this->contractor->id.'/evidence.jpg',
        'original_filename' => 'evidence.jpg',
        'mime_type' => 'image/jpeg',
        'byte_size' => 1024,
        'status' => 'available',
        'uploaded_by_user_id' => $this->owner->id,
    ]);
    Storage::disk('private')->put($this->attachment->storage_path, 'fake-image-bytes');

    $this->act = ContractorAct::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_id' => $this->contractor->id,
        'contract_id' => $this->contract->id,
        'project_id' => $this->project->id,
        'submitted_by_user_id' => $this->owner->id,
        'evidence_attachment_ids' => [$this->attachment->id],
        'submitted_at' => now(),
        'status' => 'pending_review',
    ]);
});

test('an authorized user can open a contractor act\'s own evidence attachment, and the Resource exposes a real preview URL', function () {
    $this->actingAs($this->owner)
        ->get(route('contractors.contracts.show', [$this->contractor, $this->contract]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('acts.0.evidence.0.url', fn ($url) => str_contains(
            $url,
            "/contractors/{$this->contractor->id}/acts/{$this->act->id}/attachments/{$this->attachment->id}",
        )));

    $this->actingAs($this->owner)
        ->get(route('contractors.acts.attachments.show', [$this->contractor, $this->act, $this->attachment]))
        ->assertOk();
});

test('an attachment id from a different act is rejected, never served through another act\'s URL', function () {
    $otherAct = ContractorAct::query()->create([
        'organization_id' => $this->organization->id,
        'contractor_id' => $this->contractor->id,
        'contract_id' => $this->contract->id,
        'project_id' => $this->project->id,
        'submitted_by_user_id' => $this->owner->id,
        'evidence_attachment_ids' => [],
        'submitted_at' => now(),
        'status' => 'pending_review',
    ]);

    // The real owning act can open it...
    $this->actingAs($this->owner)
        ->get(route('contractors.acts.attachments.show', [$this->contractor, $this->act, $this->attachment]))
        ->assertOk();

    // ...but the same attachment id is rejected through a different act's
    // URL, even one the same user can otherwise view, and even though both
    // acts belong to the same Contractor.
    $this->actingAs($this->owner)
        ->get(route('contractors.acts.attachments.show', [$this->contractor, $otherAct, $this->attachment]))
        ->assertNotFound();
});

test('a user from a different organization cannot reach the evidence attachment by guessing the id', function () {
    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $otherUser = User::factory()->create([
        'organization_id' => $otherOrganization->id,
        'current_organization_id' => $otherOrganization->id,
    ]);

    $this->actingAs($otherUser)
        ->get(route('contractors.acts.attachments.show', [$this->contractor, $this->act, $this->attachment]))
        ->assertNotFound();
});
