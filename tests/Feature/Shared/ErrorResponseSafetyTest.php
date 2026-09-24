<?php

use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Assets\StocktakeController;
use Illuminate\Support\Facades\Route;

/**
 * Stage 0 regression guards for the two audit findings that were reproduced
 * directly in the browser against the live deployment:
 *
 *  - A01 / acceptance SEC-01 (P0): a 500 response must never carry SQL, a
 *    stack trace, a file path, a cookie/header value or any secret — only a
 *    correlation reference the operator can quote to support.
 *  - A03 (P1): `/assets/stocktakes` 404'd because the single-segment
 *    `/assets/{asset}` wildcard was registered FIRST and swallowed the
 *    literal path, failing route-model binding.
 */
test('a 500 response exposes no SQL, stack trace, file path or cookie value', function () {
    config(['app.debug' => false]);

    Route::middleware('web')->get('/__test_explode', function () {
        throw new RuntimeException('SQLSTATE[42883]: function max(uuid) does not exist');
    });

    $response = $this->withCookie('secret_session_cookie', 'super-secret-value')
        ->get('/__test_explode');

    $response->assertStatus(500);
    $body = $response->getContent();

    expect($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain('max(uuid)')
        ->and($body)->not->toContain('super-secret-value')
        ->and($body)->not->toContain('secret_session_cookie')
        // Stack-trace / file-path shapes.
        ->and($body)->not->toContain('vendor/laravel')
        ->and($body)->not->toContain('app/Http/Controllers')
        ->and($body)->not->toContain('.php:')
        // The safe half of the contract: the operator still gets a pointer.
        ->and($body)->toContain('ODA CRM');

    // The correlation id is also returned as a header, which is what ties a
    // user-reported reference to the full server-side log entry.
    expect($response->headers->get('X-Request-Id'))->not->toBeNull();
});

test('assets/stocktakes routes to the stocktake controller, not the asset wildcard', function () {
    $route = Route::getRoutes()->match(
        Request::create('/assets/stocktakes', 'GET')
    );

    expect($route->getActionName())->toContain(StocktakeController::class)
        ->and($route->getActionName())->not->toContain(AssetController::class);
});

test('a real asset id still resolves to the asset detail route', function () {
    $route = Route::getRoutes()->match(
        Request::create('/assets/0199c4f7-0000-7000-8000-000000000000', 'GET')
    );

    expect($route->getActionName())->toContain(AssetController::class);
});
