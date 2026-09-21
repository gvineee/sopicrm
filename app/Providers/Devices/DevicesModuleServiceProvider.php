<?php

namespace App\Providers\Devices;

use App\Domain\Devices\Adapters\SimulatorDeviceAdapter;
use App\Domain\Devices\Adapters\SupremaGSdkAdapter;
use App\Domain\Devices\Contracts\AccessControlProviderInterface;
use App\Domain\Devices\Contracts\DeviceAdapterInterface;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Http\Middleware\EnsureDeviceConnectorRequest;
use App\Policies\CredentialPolicy;
use App\Policies\DevicePolicy;
use App\Policies\ExternalIdentifierMappingPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use InvalidArgumentException;

class DevicesModuleServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        Device::class => DevicePolicy::class,
        Credential::class => CredentialPolicy::class,
        ExternalIdentifierMapping::class => ExternalIdentifierMappingPolicy::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(config_path('modules/devices.php'), 'devices');

        $this->app->bind(DeviceAdapterInterface::class, function (): DeviceAdapterInterface {
            return match (config('devices.adapter')) {
                'simulator' => new SimulatorDeviceAdapter,
                'suprema' => new SupremaGSdkAdapter,
                default => throw new InvalidArgumentException('Unknown devices.adapter configuration.'),
            };
        });

        $this->app->alias(DeviceAdapterInterface::class, AccessControlProviderInterface::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->app->make(Router::class)->aliasMiddleware('device-connector', EnsureDeviceConnectorRequest::class);
    }
}
