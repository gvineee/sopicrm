<?php

namespace App\Providers\Tasks;

use App\Domain\Tasks\Models\Task;
use App\Policies\TaskPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Tasks module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md §3.8).
 * Registers App\Policies\TaskPolicy (existed with no module provider wiring
 * it up yet) and merges config/modules/tasks.php under the `tasks` config
 * key — App\Domain\Tasks\Actions\UploadTaskAttachment reads
 * config('tasks.attachments'), which only resolves once this merge runs.
 */
class TasksModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Task::class => TaskPolicy::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(config_path('modules/tasks.php'), 'tasks');
    }

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
