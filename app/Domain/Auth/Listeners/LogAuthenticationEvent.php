<?php

namespace App\Domain\Auth\Listeners;

use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * spec section 21: "password hashing ... rate limiting, login audit ...".
 * Rate limiting is Fortify's own (config/fortify.php + the 'login'
 * RateLimiter in app/Providers/FortifyServiceProvider.php, already
 * 5/minute per email+IP). This listener is the "login audit" half:
 * every successful login, failed attempt (against a KNOWN user — see
 * AuditLogger::log()'s docblock for why an unknown-email attempt has no
 * organization to attribute it to), logout, and lockout is written to
 * audit_events. Registered by App\Providers\Auth\AuthModuleServiceProvider.
 */
class LogAuthenticationEvent
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->auditLogger->log(
            action: 'auth.login.succeeded',
            target: $event->user,
            organizationId: $event->user->organization_id,
        );
    }

    public function handleFailed(Failed $event): void
    {
        if (! $event->user instanceof User) {
            // Unknown email: nothing tenant-scoped to attribute this to.
            // The rate limiter still throttles the attempt regardless.
            return;
        }

        $this->auditLogger->log(
            action: 'auth.login.failed',
            target: $event->user,
            organizationId: $event->user->organization_id,
        );
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogger->log(
            action: 'auth.logout',
            target: $event->user,
            organizationId: $event->user->organization_id,
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        $user = is_string($email) ? User::where('email', $email)->first() : null;

        if ($user === null) {
            return;
        }

        $this->auditLogger->log(
            action: 'auth.login.locked_out',
            target: $user,
            organizationId: $user->organization_id,
        );
    }
}
