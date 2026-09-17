<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Foundation-owned, per docs/architecture.md §3.8: registered ONCE in
 * bootstrap/providers.php. A module needing to register something (a policy
 * binding, an event listener, a custom auth provider, ...) does so via its
 * own `app/Providers/<Module>/<Module>ModuleServiceProvider.php` — never by
 * editing bootstrap/providers.php or this file directly — and this
 * aggregator discovers and registers every one of them automatically.
 *
 * Naming convention: a module service provider's class name MUST end in
 * `ModuleServiceProvider` (e.g. `AuthModuleServiceProvider`,
 * `PayrollModuleServiceProvider`). This distinguishes module providers from
 * framework/starter-kit providers that stay explicitly listed in
 * bootstrap/providers.php (AppServiceProvider, FortifyServiceProvider) so
 * neither set double-registers the other.
 */
class ModuleServiceProviderAggregator extends ServiceProvider
{
    public function register(): void
    {
        foreach ($this->discover() as $class) {
            $this->app->register($class);
        }
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    private function discover(): array
    {
        $files = glob(app_path('Providers/*/*ModuleServiceProvider.php')) ?: [];

        $classes = [];

        foreach ($files as $file) {
            $moduleDir = basename(dirname($file));
            $class = basename($file, '.php');
            $fqcn = "App\\Providers\\{$moduleDir}\\{$class}";

            if (is_subclass_of($fqcn, ServiceProvider::class)) {
                $classes[] = $fqcn;
            }
        }

        sort($classes);

        return $classes;
    }
}
