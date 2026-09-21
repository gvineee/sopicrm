<?php

namespace App\Providers\Payroll;

use App\Domain\Payroll\Models\Advance;
use App\Domain\Payroll\Models\DailyPayPolicy;
use App\Domain\Payroll\Models\Payment;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\PayRun;
use App\Policies\AdvancePolicy;
use App\Policies\DailyPayPolicyPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PayPeriodPolicy;
use App\Policies\PayRunPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Payroll module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md §3.8).
 */
class PayrollModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        PayPeriod::class => PayPeriodPolicy::class,
        PayRun::class => PayRunPolicy::class,
        Advance::class => AdvancePolicy::class,
        Payment::class => PaymentPolicy::class,
        DailyPayPolicy::class => DailyPayPolicyPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
