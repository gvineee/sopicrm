<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\DeviceConnectorNonce;
use App\Domain\Shared\Services\CurrentOrganization;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the internal connector contract to an organization-bound
 * Sanctum machine identity and rejects replayed or stale requests.
 */
class EnsureDeviceConnectorRequest
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $principal = $this->principal($request);

        if (! $principal instanceof Organization || ! $principal->tokenCan($ability)) {
            return $this->error($request, 403, 'connector_ability_denied', 'The machine token lacks the required connector ability.');
        }

        // The API group starts before route-level Sanctum authentication,
        // so establish tenant context here from the authenticated machine
        // principal before any tenant-scoped connector query is executed.
        CurrentOrganization::set($principal->getKey());

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.current_org_id', ?, false)", [$principal->getKey()]);
        }

        $token = $principal->currentAccessToken();
        $timestampHeader = $request->header('X-Connector-Timestamp');
        $nonce = $request->header('X-Connector-Nonce');

        if (! is_string($timestampHeader) || ! is_string($nonce)) {
            return $this->error($request, 400, 'connector_replay_headers_required', 'X-Connector-Timestamp and X-Connector-Nonce headers are required.');
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $nonce)) {
            return $this->error($request, 422, 'connector_nonce_invalid', 'X-Connector-Nonce must be 16-128 safe ASCII characters.');
        }

        try {
            $requestTimestamp = ctype_digit($timestampHeader)
                ? CarbonImmutable::createFromTimestampUTC((int) $timestampHeader)
                : CarbonImmutable::parse($timestampHeader)->utc();
        } catch (\Throwable) {
            return $this->error($request, 422, 'connector_timestamp_invalid', 'X-Connector-Timestamp must be a Unix timestamp or ISO-8601 value.');
        }

        $windowSeconds = (int) config('devices.connector_replay_window_seconds', 300);

        if (abs(now()->diffInSeconds($requestTimestamp, false)) > $windowSeconds) {
            return $this->error($request, 409, 'connector_timestamp_stale', 'The connector request timestamp is outside the accepted replay window.');
        }

        try {
            DeviceConnectorNonce::create([
                'personal_access_token_id' => $token->getKey(),
                'nonce' => $nonce,
                'request_timestamp' => $requestTimestamp,
                'expires_at' => now()->addSeconds($windowSeconds),
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return $this->error($request, 409, 'connector_request_replayed', 'This connector nonce has already been used.');
            }

            throw $exception;
        }

        return $next($request);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    private function principal(Request $request): ?object
    {
        // Sanctum resolves tokenables dynamically (User or Organization),
        // while Request::user()'s framework PHPDoc only describes User.
        return $request->user();
    }

    private function error(Request $request, int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'fieldErrors' => [],
            'requestId' => $request->attributes->get('request_id'),
        ], $status);
    }
}
