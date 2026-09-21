<?php

namespace App\Http\Controllers;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCheckpoint;
use App\Domain\Notifications\Models\OfflineSyncSubmission;
use App\Domain\Shared\Models\OutboxEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * OPS-01 (docs/runbook.md's own "Health / monitoring" placeholder — no
 * `/health`/`/ready` endpoint existed anywhere in this codebase before
 * this pass, confirmed via grep).
 *
 * `/health` is a cheap, PUBLIC liveness probe only: process is up, its own
 * DB connection and Redis are reachable. Zero per-tenant data, zero
 * internal detail beyond a boolean per dependency — safe to expose to an
 * unauthenticated load balancer/uptime checker with no information
 * disclosure. Never renders a stack trace even on failure (each dependency
 * check is individually caught; only a boolean crosses this boundary).
 *
 * `/ready` is deliberately NOT public. A readiness probe that enumerates
 * real operational counts (outbox backlog, device staleness, offline-sync
 * review queue) across the whole system is a genuine information-
 * disclosure and DoS-amplification surface if left unauthenticated — an
 * external caller could otherwise learn how large an organization's queue
 * backlogs are, or trigger repeated cross-tenant aggregate queries for
 * free. Gated on `is_platform_admin` (ADMIN-01's existing durable column,
 * reused rather than inventing a second admin concept) via the `auth`
 * guard — a real infra monitoring tool authenticates as a platform-admin
 * account (or a future dedicated machine token scoped for this one
 * purpose, not built here) rather than hitting this anonymously. `/health`
 * alone is what an external load balancer should actually poll.
 */
class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'unhealthy',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    public function ready(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_platform_admin, 403);

        $oldestPendingOutbox = OutboxEvent::withoutTenantScope()
            ->whereNull('processed_at')
            ->oldest('occurred_in_transaction_at')
            ->value('occurred_in_transaction_at');

        $pendingOutboxCount = OutboxEvent::withoutTenantScope()
            ->whereNull('processed_at')
            ->count();

        $newestCheckpoint = DeviceCheckpoint::withoutTenantScope()
            ->max('last_confirmed_at');

        $totalDeviceCount = Device::withoutTenantScope()->count();

        $staleDeviceThreshold = Carbon::now()->subMinutes(30);
        $staleDeviceCount = Device::withoutTenantScope()
            ->where(function ($query) use ($staleDeviceThreshold) {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', $staleDeviceThreshold);
            })
            ->count();

        $forReviewCount = OfflineSyncSubmission::withoutTenantScope()
            ->forReview()
            ->count();

        return response()->json([
            'status' => 'ready',
            'checked_at' => Carbon::now()->toIso8601String(),
            'outbox' => [
                // Distinguish "genuinely zero pending" from "no outbox
                // activity has ever happened yet" per docs/runbook.md's own
                // explicit requirement — a fresh install with zero events
                // ever recorded reports null here, not a misleading 0 that
                // looks identical to "queue is fully drained."
                'pending_count' => $pendingOutboxCount,
                'oldest_pending_age_seconds' => $oldestPendingOutbox !== null
                    ? Carbon::parse($oldestPendingOutbox)->diffInSeconds(Carbon::now())
                    : null,
            ],
            'devices' => [
                'total_count' => $totalDeviceCount,
                'stale_count' => $totalDeviceCount > 0 ? $staleDeviceCount : null,
                'newest_checkpoint_age_seconds' => $newestCheckpoint !== null
                    ? Carbon::parse($newestCheckpoint)->diffInSeconds(Carbon::now())
                    : null,
            ],
            'offline_sync' => [
                'for_review_count' => $forReviewCount,
            ],
        ]);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
