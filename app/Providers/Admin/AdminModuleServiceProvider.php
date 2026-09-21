<?php

namespace App\Providers\Admin;

use App\Models\User;
use App\Policies\UserAccessPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Admin module's own registrations (ADMIN-02) — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md
 * §3.8).
 */
class AdminModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserAccessPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
