<?php

namespace App\Providers\Attendance;

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftTemplate;
use App\Policies\AttendanceAnomalyPolicy;
use App\Policies\AttendanceSessionPolicy;
use App\Policies\ShiftAssignmentPolicy;
use App\Policies\ShiftTemplatePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Attendance module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md §3.8).
 */
class AttendanceModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        ShiftTemplate::class => ShiftTemplatePolicy::class,
        ShiftAssignment::class => ShiftAssignmentPolicy::class,
        AttendanceSession::class => AttendanceSessionPolicy::class,
        AttendanceAnomaly::class => AttendanceAnomalyPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
