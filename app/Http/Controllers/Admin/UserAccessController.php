<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Actions\AssignUserRoleAction;
use App\Domain\Auth\Actions\DenyUserPermissionAction;
use App\Domain\Auth\Actions\GrantUserPermissionOverrideAction;
use App\Domain\Auth\Actions\RemoveUserPermissionDenialAction;
use App\Domain\Auth\Actions\RemoveUserRoleAction;
use App\Domain\Auth\Actions\RevokeUserPermissionOverrideAction;
use App\Domain\Auth\Models\UserPermissionDenial;
use App\Domain\Auth\Support\PermissionGroups;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Http\Requests\Admin\DenyPermissionRequest;
use App\Http\Requests\Admin\PermissionOverrideRequest;
use App\Http\Requests\Admin\RemoveRoleRequest;
use App\Models\User;
use Database\Seeders\RbacBaseSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * ADMIN-02: user/role/permission-override administration. Every mutating
 * action here goes through a dedicated App\Domain\Auth\Actions\* class
 * (audited, reason-required where the action is a reduction of access) —
 * this controller never mutates a role/permission grant directly. See
 * App\Policies\UserAccessPolicy for the authorization rules and
 * App\Providers\AppServiceProvider::boot() for how a deny-override takes
 * precedence over everything else at Gate-check time.
 */
class UserAccessController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $organizationId = $request->user()->organization_id;

        $users = User::query()
            ->where('organization_id', $organizationId)
            // QUEUE-01: a system actor has no roles/permissions to manage —
            // nothing here is meant for it, so it's excluded rather than
            // shown as an unmanageable, confusing row.
            ->where('is_system_account', false)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->getRoleNames()->values(),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorize('view', $user);

        return Inertia::render('Admin/Users/Show', [
            'targetUser' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'roles' => RbacBaseSeeder::ROLES,
            'assignedRoles' => $user->getRoleNames()->values(),
            'permissionGroups' => PermissionGroups::grouped()->values(),
            'effectivePermissions' => $this->effectivePermissions($user),
            'canManageRoles' => $request->user()->can('assignRole', $user),
            'canManageOverrides' => $request->user()->can('manageOverride', $user),
        ]);
    }

    public function assignRole(AssignRoleRequest $request, User $user, AssignUserRoleAction $action): RedirectResponse
    {
        $this->authorize('assignRole', $user);

        $action->execute($user, $request->string('role')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'როლი მინიჭებულია.']);
    }

    public function removeRole(RemoveRoleRequest $request, User $user, RemoveUserRoleAction $action): RedirectResponse
    {
        $this->authorize('removeRole', $user);

        $action->execute(
            $user,
            $request->string('role')->toString(),
            $request->user(),
            $request->string('reason')->toString(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'როლი მოხსნილია.']);
    }

    public function grantOverride(PermissionOverrideRequest $request, User $user, GrantUserPermissionOverrideAction $action): RedirectResponse
    {
        $this->authorize('manageOverride', $user);

        $action->execute($user, $request->string('permission')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'უფლება პირდაპირ მინიჭებულია.']);
    }

    public function revokeOverride(PermissionOverrideRequest $request, User $user, RevokeUserPermissionOverrideAction $action): RedirectResponse
    {
        $this->authorize('manageOverride', $user);

        $action->execute($user, $request->string('permission')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'პირდაპირი წვდომა მოხსნილია.']);
    }

    public function denyPermission(DenyPermissionRequest $request, User $user, DenyUserPermissionAction $action): RedirectResponse
    {
        $this->authorize('manageOverride', $user);

        $action->execute(
            $user,
            $request->string('permission')->toString(),
            $request->user(),
            $request->string('reason')->toString(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'უფლება აღკვეთილია ამ მომხმარებლისთვის.']);
    }

    public function removeDenial(Request $request, User $user, UserPermissionDenial $denial, RemoveUserPermissionDenialAction $action): RedirectResponse
    {
        $this->authorize('manageOverride', $user);

        abort_unless($denial->user_id === $user->id, 404);

        $action->execute($denial, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'აღკვეთა მოხსნილია.']);
    }

    /**
     * @return list<array{permission: string, group: string, granted: bool, source: string, via_role: string|null, denial_id: string|null}>
     */
    private function effectivePermissions(User $user): array
    {
        $directGrantedNames = $user->getDirectPermissions()->pluck('name');
        $denialIdsByPermission = UserPermissionDenial::query()
            ->where('user_id', $user->id)
            ->pluck('id', 'permission_name');

        $roleNamesByPermission = [];
        foreach ($user->getRoleNames() as $roleName) {
            $role = Role::query()->where('name', $roleName)->whereNull('organization_id')->first();
            foreach ($role?->permissions->pluck('name') ?? [] as $permissionName) {
                $roleNamesByPermission[$permissionName] ??= $roleName;
            }
        }

        return array_values(Permission::query()
            ->orderBy('name')
            ->get()
            ->map(function (Permission $permission) use ($directGrantedNames, $denialIdsByPermission, $roleNamesByPermission): array {
                $name = $permission->name;
                $denialId = $denialIdsByPermission[$name] ?? null;
                $isDenied = $denialId !== null;
                $isDirectGrant = $directGrantedNames->contains($name);
                $viaRole = $roleNamesByPermission[$name] ?? null;
                $wouldBeGranted = $isDirectGrant || $viaRole !== null;

                $source = match (true) {
                    $isDenied => 'denied',
                    $isDirectGrant => 'direct_grant',
                    $viaRole !== null => 'role',
                    default => 'none',
                };

                return [
                    'permission' => $name,
                    'group' => PermissionGroups::labelFor($name),
                    'granted' => $wouldBeGranted && ! $isDenied,
                    'source' => $source,
                    'via_role' => $viaRole !== null ? (string) $viaRole : null,
                    'denial_id' => $denialId !== null ? (string) $denialId : null,
                ];
            })
            ->filter(fn (array $row): bool => $row['source'] !== 'none')
            ->all());
    }
}
