<?php

namespace App\Providers\Contractors;

use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Contractors\Models\ContractorPayment;
use App\Policies\ContractorActPolicy;
use App\Policies\ContractorContractPolicy;
use App\Policies\ContractorPaymentPolicy;
use App\Policies\ContractorPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Contractors module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md §3.8).
 */
class ContractorsModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Contractor::class => ContractorPolicy::class,
        ContractorContract::class => ContractorContractPolicy::class,
        ContractorAct::class => ContractorActPolicy::class,
        ContractorPayment::class => ContractorPaymentPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
