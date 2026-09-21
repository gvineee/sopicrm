<?php

namespace App\Providers\Timesheets;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Attendance\Models\Timesheet;
use App\Policies\AttendanceAdjustmentPolicy;
use App\Policies\TimesheetPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Timesheets module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md §3.8).
 */
class TimesheetsModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Timesheet::class => TimesheetPolicy::class,
        AttendanceAdjustment::class => AttendanceAdjustmentPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
