<?php

namespace App\Providers\Notifications;

use App\Domain\Notifications\Adapters\FakeTelegramTransport;
use App\Domain\Notifications\Contracts\TelegramTransportInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Notifications module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md
 * §3.8). Binds the ONLY TelegramTransportInterface implementation this
 * session builds (see that interface's own docblock) — swapping in a real
 * transport later is a one-line change here, not a change to any Action
 * that depends on the interface.
 */
class NotificationsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound as a concrete singleton (not just the interface) so a test
        // can resolve `app(FakeTelegramTransport::class)` and get the SAME
        // instance the Action actually sent through, to assert on
        // `sentMessages()`/pre-arm `forceFailureFor()` — resolving only the
        // interface would construct an unrelated second instance.
        $this->app->singleton(FakeTelegramTransport::class);
        $this->app->singleton(TelegramTransportInterface::class, fn ($app) => $app->make(FakeTelegramTransport::class));
    }
}
