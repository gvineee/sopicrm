<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app is commonly served behind Cloudflare Tunnel/Nginx. Trust the
        // forwarded scheme so URL and asset generation follows the browser's
        // HTTPS request while local development remains HTTP-friendly.
        $middleware->trustProxies(at: '*');

        // PWA-01: without this, `auth:sanctum` on an /api/v1 route only
        // ever recognizes a bearer token, never this app's own first-party
        // browser session — every /api/v1/... route already written with
        // `auth:sanctum` (api-dailyjournal.php, this ticket's own
        // api-tasks.php) assumed Sanctum's SPA stateful-session mode was
        // active, but it was never actually registered. Confirmed missing
        // by a real 401 from a real browser session hitting
        // OfflineSyncController while building this ticket's own
        // Playwright end-to-end test — `php artisan test`'s `actingAs()`
        // never exercises real guard resolution, so no existing test could
        // have caught this. `statefulApi()` inserts
        // EnsureFrontendRequestsAreStateful ahead of the `api` group,
        // which recognizes a request as first-party only when it's already
        // same-session/CSRF-protected (config/sanctum.php's `stateful`
        // domain list) — machine-token callers (the device-connector,
        // DailyJournal's own external callers if any) are unaffected, since
        // they never match a stateful domain and fall through to normal
        // bearer-token auth exactly as before.
        $middleware->statefulApi();

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Audit A01 (P0): the public error response carries ONLY a
        // correlation id; the unredacted detail stays in the server log
        // under that same id. `AssignRequestId` already generates it per
        // request and echoes it as X-Request-Id, so support can map a
        // user-reported reference straight to the real log entry without
        // ever putting SQL, a stack trace, a file path or a cookie in an
        // HTTP response body. See resources/views/errors/500.blade.php for
        // the HTML side of the same contract.
        $exceptions->context(fn () => [
            'request_id' => request()->attributes->get('request_id'),
        ]);
    })->create();
