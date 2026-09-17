<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request gets a stable requestId, used by App\Domain\Shared\Services\AuditLogger
 * and returned in the unified API error shape (spec section 20: "code,
 * message, fieldErrors, requestId"). Honors an inbound `X-Request-Id` from a
 * trusted upstream proxy if present, otherwise generates one.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid7();

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
