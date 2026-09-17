<?php

namespace App\Providers\DailyJournal;

use App\Domain\DailyJournal\Models\DailyReport;
use App\Policies\DailyReportPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Daily Journal module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md §3.8).
 * Never edits bootstrap/app.php or bootstrap/providers.php directly.
 */
class DailyJournalModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        DailyReport::class => DailyReportPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
