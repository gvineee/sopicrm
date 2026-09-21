<?php

namespace App\Providers\Assets;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetIncident;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Assets\Models\Maintenance;
use App\Domain\Assets\Models\Stocktake;
use App\Policies\AssetIncidentPolicy;
use App\Policies\AssetPolicy;
use App\Policies\CustodyTransactionPolicy;
use App\Policies\MaintenancePolicy;
use App\Policies\StocktakePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Assets module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md §3.8).
 */
class AssetsModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Asset::class => AssetPolicy::class,
        CustodyTransaction::class => CustodyTransactionPolicy::class,
        AssetIncident::class => AssetIncidentPolicy::class,
        Stocktake::class => StocktakePolicy::class,
        Maintenance::class => MaintenancePolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
