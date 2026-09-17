<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ModuleServiceProviderAggregator;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    // Discovers and registers every app/Providers/<Module>/<Module>ModuleServiceProvider.php
    // — see docs/architecture.md §3.8. No later module-building agent may
    // edit this file to add its own provider directly.
    ModuleServiceProviderAggregator::class,
];
