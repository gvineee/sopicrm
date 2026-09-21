<?php

namespace App\Providers\Projects;

use App\Domain\Projects\Models\Project;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Projects module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md §3.8).
 * Registers App\Policies\ProjectPolicy, which existed but had no module
 * provider wiring it up yet (Laravel's default policy auto-discovery does
 * not reliably resolve policies for models nested under app/Domain/<Module>/
 * Models — see the identical pattern in every other *ModuleServiceProvider).
 */
class ProjectsModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Project::class => ProjectPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
