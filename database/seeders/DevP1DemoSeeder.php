<?php

namespace Database\Seeders;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Employees\Models\Team;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * DEV-ONLY DEMO DATA — never runs in production (see the guard in
 * DatabaseSeeder::run(), which is the only caller of this class). This
 * exercises a representative slice of every P0+P1 domain
 * (docs/data-model.md) end-to-end against the real factories, so a human
 * or a later module agent has real, DB-backed rows to click through /
 * query rather than an empty schema. Per spec section 21, this must never
 * be mistaken for or reused as production seed data.
 *
 * Assumes the caller (DatabaseSeeder) has already set
 * App\Domain\Shared\Services\CurrentOrganization to the target organization
 * before invoking this seeder, and clears it afterward.
 */
class DevP1DemoSeeder extends Seeder
{
    public function run(): void
    {
        // The caller (DatabaseSeeder) has already set this via
        // CurrentOrganization::set() before invoking this seeder — read it
        // back rather than querying "the first organization row," which
        // would silently pick the WRONG organization on any database that
        // already has more than one (e.g. a second `db:seed` run).
        $orgId = CurrentOrganization::requireId();

        $manager = User::factory()->create([
            'organization_id' => $orgId,
            'current_organization_id' => $orgId,
            'name' => 'Demo Project Manager',
            'email' => 'pm@example.com',
        ]);

        // --- Employees domain ------------------------------------------------
        $team = Team::factory()->create(['organization_id' => $orgId, 'name' => 'Brigade 1']);

        $foreman = Employee::factory()->create([
            'organization_id' => $orgId,
            'first_name' => 'Giorgi',
            'last_name' => 'Beridze',
            'team_id' => $team->id,
        ]);
        $team->update(['foreman_employee_id' => $foreman->id]);

        $worker = Employee::factory()->create([
            'organization_id' => $orgId,
            'first_name' => 'Nino',
            'last_name' => 'Kapanadze',
            'team_id' => $team->id,
            'supervisor_employee_id' => $foreman->id,
        ]);

        $rateHistory = RateHistory::factory()->create([
            'organization_id' => $orgId,
            'employee_id' => $worker->id,
            'project_id' => null,
            'rate_type' => 'hourly',
            'amount' => '15.00',
            'approved_by_user_id' => $manager->id,
            'effective_from' => now()->subMonths(2)->toDateString(),
        ]);

        // --- Devices domain ----------------------------------------------------
        $site = Site::factory()->create(['organization_id' => $orgId, 'name' => 'Tbilisi Site A']);
        Device::factory()->create([
            'organization_id' => $orgId,
            'site_id' => $site->id,
            'serial_number' => 'DEMO-0001',
            'status' => 'online',
            'sync_status' => 'in_sync',
        ]);

        // --- Attendance domain ---------------------------------------------
        ShiftTemplate::factory()->create(['organization_id' => $orgId, 'site_id' => $site->id]);
        AttendanceSession::factory()->create([
            'organization_id' => $orgId,
            'employee_id' => $worker->id,
            'site_id' => $site->id,
        ]);

        // --- Projects & Tasks domain -----------------------------------------
        $client = Client::factory()->create(['organization_id' => $orgId, 'name' => 'Demo Client LLC']);
        $project = Project::factory()->create([
            'organization_id' => $orgId,
            'client_id' => $client->id,
            'manager_user_id' => $manager->id,
            'name' => 'Demo Residential Tower',
        ]);

        Task::factory()->create([
            'organization_id' => $orgId,
            'project_id' => $project->id,
            'accountable_owner_employee_id' => $foreman->id,
            'title' => 'Pour foundation slab, block A',
            'status' => 'in_progress',
        ]);

        DailyReport::factory()->create([
            'organization_id' => $orgId,
            'project_id' => $project->id,
            'responsible_user_id' => $manager->id,
        ]);

        // --- Payroll domain --------------------------------------------------
        $payPeriod = PayPeriod::factory()->create(['organization_id' => $orgId]);
        $payRun = PayRun::factory()->create(['organization_id' => $orgId, 'pay_period_id' => $payPeriod->id]);
        PayRunLine::factory()->create([
            'organization_id' => $orgId,
            'pay_run_id' => $payRun->id,
            'employee_id' => $worker->id,
            'project_id' => $project->id,
            // Explicit override: PayRunLineFactory's own default for this
            // column is a bare `RateHistory::factory()`, which — left
            // unoverridden — cascades into its OWN default `employee_id`
            // (`Employee::factory()`), which cascades into ITS OWN default
            // `organization_id` (`Organization::factory()`), silently
            // creating a brand-new, unrelated tenant and violating RLS
            // (real bug hit and fixed while implementing this pass — see
            // docs/decisions.md). Reusing the RateHistory already created
            // above for $worker avoids the whole cascade.
            'rate_snapshot_id' => $rateHistory->id,
        ]);

        // --- Assets domain -----------------------------------------------------
        $asset = Asset::factory()->create(['organization_id' => $orgId, 'name' => 'Bosch Rotary Hammer']);
        AssetActiveCustody::factory()->create([
            'organization_id' => $orgId,
            'asset_id' => $asset->id,
            'status' => 'issued',
        ]);
        $custody = CustodyTransaction::factory()->create([
            'organization_id' => $orgId,
            'receiving_employee_id' => $worker->id,
            'project_id' => $project->id,
            'issued_by_user_id' => $manager->id,
        ]);
        CustodyLine::factory()->create([
            'organization_id' => $orgId,
            'custody_transaction_id' => $custody->id,
            'asset_id' => $asset->id,
        ]);
    }
}
