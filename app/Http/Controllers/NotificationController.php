<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Support\NotificationType;
use App\Domain\Shared\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * NOTIFY-01: every action here reads/writes only the CURRENT user's own
 * notifications/preferences — derived from `$request->user()->id` only,
 * never a route parameter, so there is no IDOR surface by construction
 * (same pattern App\Http\Controllers\MyProfileController already
 * established in this codebase). JSON endpoints (not Inertia::render) since
 * the bell/list are polled from the shared layout on every page, not a
 * full navigation.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json([
            'notifications' => $notifications->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->payload['title'] ?? '',
                'message' => $n->payload['message'] ?? '',
                'deep_link' => $n->deep_link,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'unread_count' => Notification::query()
                ->where('recipient_user_id', $request->user()->id)
                ->unread()
                ->count(),
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id, 404);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['status' => 'ok']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    public function preferencesPage(Request $request): Response
    {
        $preference = $this->currentPreference($request);

        return Inertia::render('Notifications/Preferences', [
            'mutedTypes' => $preference->muted_types ?? [],
            'availableTypes' => NotificationType::labels(),
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $preference = $this->currentPreference($request);

        return response()->json([
            'muted_types' => $preference->muted_types ?? [],
            'available_types' => NotificationType::labels(),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'muted_types' => ['array'],
            'muted_types.*' => [Rule::in(NotificationType::all())],
        ]);

        $preference = $this->currentPreference($request);
        $preference->update(['muted_types' => $validated['muted_types'] ?? []]);

        return response()->json(['status' => 'ok', 'muted_types' => $preference->muted_types]);
    }

    private function currentPreference(Request $request): NotificationPreference
    {
        return NotificationPreference::query()->firstOrCreate(
            [
                'organization_id' => $request->user()->organization_id,
                'user_id' => $request->user()->id,
            ],
            ['muted_types' => []],
        );
    }
}
