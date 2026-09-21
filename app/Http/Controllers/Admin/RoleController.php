<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Support\PermissionGroups;
use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\RbacBaseSeeder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * ADMIN-02: READ-ONLY role/permission listing. Role→permission grants stay
 * code/seeder-owned this pass (database/seeders/modules/*PermissionsSeeder.php)
 * — making the role catalog itself runtime-editable is deliberately out of
 * scope, recorded as a next slice in docs/claude-overnight-progress.md,
 * mirroring how TENANT-01 was deliberately scoped down earlier this session
 * rather than rushed. User-level overrides (this module's actual
 * configurable surface) live in UserAccessController instead.
 */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $groups = PermissionGroups::grouped();

        $roles = Role::query()
            ->whereIn('name', RbacBaseSeeder::ROLES)
            ->whereNull('organization_id')
            ->with('permissions')
            ->get()
            ->map(function (Role $role) use ($groups): array {
                $permissionNames = $role->permissions->pluck('name');

                return [
                    'name' => $role->name,
                    'permission_groups' => $groups
                        ->map(fn (array $group): array => [
                            'key' => $group['key'],
                            'label' => $group['label'],
                            'permissions' => array_values(array_intersect($group['permissions'], $permissionNames->all())),
                        ])
                        ->filter(fn (array $group): bool => $group['permissions'] !== [])
                        ->values(),
                ];
            })
            ->sortBy('name')
            ->values();

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles,
        ]);
    }
}
