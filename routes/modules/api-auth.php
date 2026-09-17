<?php

use App\Domain\Auth\Support\ApiPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Auth module's /api/v1 routes (docs/architecture.md §3.1). Every route
 * here authenticates via the `sanctum` guard (session-authenticated Inertia
 * pages never call /api/v1 — spec section 18: "Inertia ჩვეულებრივი
 * გვერდებისთვის; /api/v1 ... adapter-ის და საჭირო ინტეგრაციებისთვის").
 */
Route::middleware(['auth:sanctum'])
    ->prefix('v1')
    ->group(function (): void {
        // Minimal identity-check endpoint: lets a machine client (or a
        // future first-party mobile app) confirm which organization its
        // token is bound to, without exposing any business data. Doubles as
        // the reference example other modules' api-<module>.php files
        // should follow for deriving tenant context from the authenticated
        // principal rather than trusting a payload.
        Route::get('/me', fn (Request $request) => response()->json(ApiPrincipal::describe($request->user())))
            ->name('api.auth.me');
    });
