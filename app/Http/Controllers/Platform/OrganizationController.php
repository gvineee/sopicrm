<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Auth\Actions\CreateOrganizationAction;
use App\Domain\Auth\Actions\PurgeOrganizationAction;
use App\Domain\Auth\Actions\UpdateOrganizationAction;
use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Support\OrganizationDatabaseContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\DestroyOrganizationRequest;
use App\Http\Requests\Platform\StoreOrganizationRequest;
use App\Http\Requests\Platform\UpdateOrganizationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-level organization (tenant) administration: list, create with a
 * first owner, rename, and hard-delete. Everything is gated on the
 * `manage-organizations` ability, which only `users.is_platform_admin`
 * satisfies (App\Providers\Auth\AuthModuleServiceProvider) — no tenant role
 * reaches it.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-organizations');

        /** @var User $user */
        $user = $request->user();
        $ownOrganizationIds = array_values(array_unique(array_filter([$user->organization_id, $user->current_organization_id])));

        $organizations = Organization::query()
            ->orderBy('created_at')
            ->get()
            ->map(fn (Organization $organization): array => [
                ...$this->resource($organization),
                'is_current' => in_array($organization->id, $ownOrganizationIds, true),
                ...$this->counts($organization->id),
            ])
            ->values();

        return Inertia::render('Platform/Organizations/Index', [
            'organizations' => $organizations,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('manage-organizations');

        return Inertia::render('Platform/Organizations/Create');
    }

    public function store(StoreOrganizationRequest $request, CreateOrganizationAction $action): RedirectResponse
    {
        $this->authorize('manage-organizations');

        $action->execute($request->organizationData(), $request->ownerData(), $request->user());

        return to_route('platform.organizations.index')->with('toast', [
            'type' => 'success',
            'message' => 'ორგანიზაცია და მისი მფლობელი შეიქმნა.',
        ]);
    }

    public function edit(Request $request, Organization $organization): Response
    {
        $this->authorize('manage-organizations');

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Platform/Organizations/Edit', [
            'organization' => [
                ...$this->resource($organization),
                ...$this->counts($organization->id),
            ],
            'isOwnOrganization' => in_array($organization->id, [$user->organization_id, $user->current_organization_id], true),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, UpdateOrganizationAction $action): RedirectResponse
    {
        $this->authorize('manage-organizations');

        $action->execute($organization, $request->organizationData(), $request->user());

        return to_route('platform.organizations.index')->with('toast', [
            'type' => 'success',
            'message' => 'ორგანიზაციის მონაცემები განახლდა.',
        ]);
    }

    public function destroy(DestroyOrganizationRequest $request, Organization $organization, PurgeOrganizationAction $action): RedirectResponse
    {
        $this->authorize('manage-organizations');

        $name = $organization->name;
        $counts = $action->execute($organization, $request->user(), $request->string('confirmation_name')->toString());

        return to_route('platform.organizations.index')->with('toast', [
            'type' => 'success',
            'message' => "ორგანიზაცია „{$name}“ სრულად წაიშალა (".array_sum($counts).' ჩანაწერი).',
        ]);
    }

    /** @return array{id: string, name: string, legal_name: string|null, default_currency: string, default_timezone: string, is_active: bool, created_at: string|null} */
    private function resource(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'legal_name' => $organization->legal_name,
            'default_currency' => $organization->default_currency,
            'default_timezone' => $organization->default_timezone,
            'is_active' => $organization->is_active,
            'created_at' => $organization->created_at?->toIso8601String(),
        ];
    }

    /**
     * Counted inside the organization's own RLS context — from the admin's
     * context every other organization's employees and projects would read
     * as zero, which is exactly the wrong signal on a screen used to decide
     * what is safe to delete.
     *
     * @return array{users_count: int, employees_count: int, projects_count: int}
     */
    private function counts(string $organizationId): array
    {
        return OrganizationDatabaseContext::run($organizationId, fn (): array => [
            'users_count' => DB::table('users')
                ->where('organization_id', $organizationId)
                ->where('is_system_account', false)
                ->count(),
            'employees_count' => DB::table('employees')->where('organization_id', $organizationId)->count(),
            'projects_count' => DB::table('projects')->where('organization_id', $organizationId)->count(),
        ]);
    }
}
