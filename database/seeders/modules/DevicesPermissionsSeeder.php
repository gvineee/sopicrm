<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DevicesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'devices.view',
            'devices.manage',
            'devices.credentials.view',
            'devices.credentials.manage',
            'devices.simulator.manage',
            'devices.connector.ingest',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (['owner', 'system_admin'] as $roleName) {
            Role::query()->where('name', $roleName)->whereNull('organization_id')->firstOrFail()
                ->givePermissionTo($permissions);
        }

        Role::query()->where('name', 'hr')->whereNull('organization_id')->firstOrFail()
            ->givePermissionTo(['devices.credentials.view', 'devices.credentials.manage']);
    }
}
