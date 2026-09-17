<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Models\IdempotencyRecord;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/data-model.md "idempotency_records" / DEC-015 / spec section 20:
 * "ფულის, მარაგისა და submission POST ოპერაციებზე Idempotency-Key; იგივე
 * გასაღები განსხვავებული payload-ით იწვევს conflict-ს." Applied per-route
 * via the `idempotency` middleware alias (registered by
 * App\Providers\Auth\AuthModuleServiceProvider) on money/stock/submission
 * POST endpoints — never globally, since most endpoints aren't
 * idempotency-key operations.
 *
 * Same key + same request body (by hash) on the same endpoint replays the
 * exact cached response instead of re-running the action. Same key + a
 * DIFFERENT body is a 409 Conflict, per the spec's explicit rule, and never
 * touches the underlying action at all.
 */
class EnsureIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return $this->errorResponse($request, 400, 'idempotency_key_required', 'Idempotency-Key header is required for this operation.');
        }

        $organizationId = CurrentOrganization::requireId();
        $endpointSignature = $request->route()?->getName() ?? ($request->method().' '.$request->path());
        $requestHash = hash('sha256', $request->getContent());

        $existing = IdempotencyRecord::query()
            ->where('idempotency_key', $key)
            ->where('endpoint_signature', $endpointSignature)
            ->first();

        if ($existing !== null) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                return $this->errorResponse(
                    $request,
                    409,
                    'idempotency_key_conflict',
                    'This Idempotency-Key was already used with a different request payload.'
                );
            }

            return response()->json($existing->response_body, $existing->response_status)
                ->header('Idempotency-Replayed', 'true');
        }

        $response = $next($request);

        $principal = $request->user();

        IdempotencyRecord::create([
            'organization_id' => $organizationId,
            // Machine tokens authenticate as an Organization, not a User;
            // never place an organization UUID into the users FK.
            'user_id' => $principal instanceof User ? $principal->getAuthIdentifier() : null,
            'idempotency_key' => $key,
            'endpoint_signature' => $endpointSignature,
            'request_hash' => $requestHash,
            'response_status' => $response->getStatusCode(),
            'response_body' => $this->decodeBody($response),
            'created_at' => now(),
        ]);

        return $response;
    }

    private function decodeBody(Response $response): mixed
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }

        return json_decode($response->getContent() ?: '{}', true);
    }

    private function errorResponse(Request $request, int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'fieldErrors' => [],
            'requestId' => $request->attributes->get('request_id'),
        ], $status);
    }
}
