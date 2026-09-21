<?php

namespace App\Providers\Employees;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Position;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Employees\Models\Team;
use App\Policies\EmployeePolicy;
use App\Policies\PositionPolicy;
use App\Policies\RateHistoryPolicy;
use App\Policies\TeamPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Employees module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md §3.8).
 * Registers this module's Policies and merges its own config file
 * (config/modules/employees.php) under the `employees` config key, since
 * Laravel's default ConfigServiceProvider only auto-loads top-level
 * config/*.php files, not the config/modules/ subdirectory.
 */
class EmployeesModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Employee::class => EmployeePolicy::class,
        Position::class => PositionPolicy::class,
        RateHistory::class => RateHistoryPolicy::class,
        Team::class => TeamPolicy::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(config_path('modules/employees.php'), 'employees');
    }

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
