<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\Actions\CompleteTelegramLinkAction;
use App\Domain\Notifications\Actions\GenerateTelegramLinkCodeAction;
use App\Domain\Notifications\Actions\SendTelegramReportAction;
use App\Domain\Notifications\Exceptions\TelegramLinkCodeInvalidException;
use App\Domain\Notifications\Exceptions\TelegramReportNotAuthorizedException;
use App\Domain\Notifications\Models\TelegramLink;
use App\Domain\Notifications\Models\TelegramReportDelivery;
use App\Domain\Notifications\Support\TelegramReportType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * NOTIFY-01: own-account-only actions (never a route parameter identifying
 * another user), same IDOR-free pattern as NotificationController. No real
 * Telegram webhook exists in this codebase (mocked transport — see
 * App\Domain\Notifications\Contracts\TelegramTransportInterface's own
 * docblock); `completeDemo()` below is explicitly restricted to
 * local/testing so the linking flow is demonstrable/testable end-to-end
 * without ever exposing an unauthenticated cross-tenant lookup route in a
 * real deployment — mirrors this codebase's existing `design-system/*`
 * QA-route precedent (docs/runbook.md) for the same "demo-only, gated by
 * environment" shape.
 */
class TelegramLinkController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $link = TelegramLink::query()
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'linked' => $link?->isLinked() ?? false,
            'pending_code' => ($link !== null && ! $link->isLinked() && $link->isCodeUsable()) ? $link->link_code : null,
            'report_types' => TelegramReportType::all(),
            'deliveries' => $link === null ? [] : TelegramReportDelivery::query()
                ->where('telegram_link_id', $link->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'report_type', 'status', 'failed_reason', 'sent_at', 'created_at']),
        ]);
    }

    public function link(Request $request, GenerateTelegramLinkCodeAction $action): JsonResponse
    {
        $link = $action->execute($request->user());

        return response()->json(['code' => $link->link_code, 'expires_at' => $link->link_code_expires_at->toIso8601String()]);
    }

    /**
     * Local/testing only — see this class's own docblock. Simulates the
     * bot side completing the handshake for the CURRENT user's own
     * already-generated code, with a synthetic chat id.
     */
    public function completeDemo(Request $request, CompleteTelegramLinkAction $action): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $link = TelegramLink::query()->where('user_id', $request->user()->id)->firstOrFail();

        try {
            $action->execute($link->link_code, 'demo-'.$request->user()->id);
        } catch (TelegramLinkCodeInvalidException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['status' => 'linked']);
    }

    public function sendReport(Request $request, SendTelegramReportAction $action): JsonResponse
    {
        $validated = $request->validate([
            'report_type' => ['required', Rule::in(TelegramReportType::all())],
        ]);

        $link = TelegramLink::query()->where('user_id', $request->user()->id)->first();

        if ($link === null) {
            return response()->json(['error' => 'Telegram is not linked.'], 422);
        }

        try {
            $delivery = $action->execute($link, $validated['report_type'], $request->user());
        } catch (TelegramReportNotAuthorizedException $exception) {
            return response()->json(['error' => $exception->getMessage()], 403);
        }

        return response()->json(['delivery' => $delivery->only(['id', 'status', 'failed_reason'])]);
    }

    public function retryDelivery(Request $request, TelegramReportDelivery $delivery, SendTelegramReportAction $action): JsonResponse
    {
        abort_unless($delivery->telegramLink->user_id === $request->user()->id, 404);

        try {
            $delivery = $action->retry($delivery);
        } catch (TelegramReportNotAuthorizedException $exception) {
            return response()->json(['error' => $exception->getMessage()], 403);
        }

        return response()->json(['delivery' => $delivery->only(['id', 'status', 'failed_reason'])]);
    }
}
