<?php

namespace Database\Seeders;

use App\Domain\Auth\Models\Organization;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\CompanyMembership;
use App\Domain\Shared\Services\CurrentCompany;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * spec section 21 / docs/runbook.md: "production seed data should NOT
     * be created." The RBAC role/permission catalog is reference data, not
     * production seed data, and always runs. The demo organization + user
     * below only runs outside production, for local/dev/CI convenience.
     */
    public function run(): void
    {
        $this->call(AggregatingPermissionsSeeder::class);

        if (app()->environment('production')) {
            return;
        }

        /** @var Organization $organization */
        $organization = Organization::factory()->create(['name' => 'ODA Demo']);

        CurrentOrganization::set($organization->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

        // RLS-protected tables (docs/architecture.md §4) check Postgres's
        // own `app.current_org_id` session GUC, not just the Eloquent-layer
        // CurrentOrganization holder — a console context (this seeder) has
        // no HTTP middleware to set it, so it's set here directly, mirroring
        // App\Http\Middleware\SetCurrentOrganization's own pattern.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.current_org_id', ?, false)", [$organization->id]);
        }

        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ODA Demo',
            'code' => 'DEFAULT',
        ]);
        CurrentCompany::set($company->id);

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'current_organization_id' => $organization->id,
            'current_company_id' => $company->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $user->assignRole('owner');

        CompanyMembership::factory()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'is_primary' => true,
        ]);

        // P0+P1 schema pass demo data (Employees/Devices/Attendance/
        // Payroll/Assets/Projects&Tasks) — dev/CI convenience only, never
        // production (see the environment guard above and
        // DevP1DemoSeeder's own docblock).
        $this->call(DevP1DemoSeeder::class);

        CurrentOrganization::clear();
        CurrentCompany::clear();

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.current_org_id', '', false)");
        }
    }
}
