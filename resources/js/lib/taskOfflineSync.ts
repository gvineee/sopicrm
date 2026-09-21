import type { OfflineQueueItem } from '@/types';
import { enqueue, getBlob, listByUser, updateStatus } from '@/lib/offlineQueue';

/**
 * PWA-01: the real send/replay wiring between resources/js/pages/MyDay.vue
 * and the two /api/v1 endpoints routes/modules/api-tasks.php exposes
 * (App\Http\Controllers\Api\V1\Tasks\OfflineSyncController). Kept separate
 * from the generic resources/js/lib/offlineQueue.ts primitive (which knows
 * nothing about Tasks specifically) and from resources/js/lib/api.ts (whose
 * simple JSON-body contract doesn't fit a multipart photo upload or the
 * need to read a non-2xx status without throwing).
 */

export type TaskPhotoPayload = {
    taskId: string;
    projectId: string;
    classification?: string | null;
    caption?: string | null;
};

export type TaskSubmissionPayload = {
    taskId: string;
    projectId: string;
    comment: string;
    submitted_quantity: string | null;
    attachment_ids: string[];
};

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

    return match ? decodeURIComponent(match[1]) : null;
}

function authHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = readCookie('XSRF-TOKEN');
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    return headers;
}

/**
 * Maps the API's own 3-way outcome (see OfflineSyncController's docblock)
 * onto offlineQueue.replayQueued()'s 3-value contract. `for_review` and
 * `rejected` both mean "a human needs to look, stop auto-retrying" — the
 * durable distinction between them lives server-side in the
 * OfflineSyncSubmission row, not in the client queue's own status.
 */
async function sendPhoto(item: OfflineQueueItem<TaskPhotoPayload>): Promise<'sent' | 'conflict' | 'failed'> {
    if (!item.blobId) {
        return 'failed';
    }

    const blob = await getBlob(item.blobId);
    if (!blob) {
        return 'failed';
    }

    const formData = new FormData();
    formData.append('file', blob, 'photo.jpg');
    formData.append('client_item_id', item.id);
    formData.append('client_created_at', item.createdAt);
    if (item.payload.classification) formData.append('classification', item.payload.classification);
    if (item.payload.caption) formData.append('caption', item.payload.caption);

    const response = await fetch(
        `/api/v1/projects/${item.payload.projectId}/tasks/${item.payload.taskId}/offline-attachments`,
        {
            method: 'POST',
            headers: { ...authHeaders(), 'Idempotency-Key': item.idempotencyKey },
            credentials: 'same-origin',
            body: formData,
        },
    );

    if (response.ok) return 'sent';
    if (response.status === 409) return 'conflict';
    if (response.status === 422) return 'conflict';

    return 'failed';
}

async function sendSubmission(item: OfflineQueueItem<TaskSubmissionPayload>): Promise<'sent' | 'conflict' | 'failed'> {
    const response = await fetch(
        `/api/v1/projects/${item.payload.projectId}/tasks/${item.payload.taskId}/offline-submissions`,
        {
            method: 'POST',
            headers: { ...authHeaders(), 'Content-Type': 'application/json', 'Idempotency-Key': item.idempotencyKey },
            credentials: 'same-origin',
            body: JSON.stringify({
                comment: item.payload.comment,
                submitted_quantity: item.payload.submitted_quantity,
                attachment_ids: item.payload.attachment_ids,
                client_item_id: item.id,
                client_created_at: item.createdAt,
            }),
        },
    );

    if (response.ok) return 'sent';
    if (response.status === 409) return 'conflict';
    if (response.status === 422) return 'conflict';

    return 'failed';
}

export async function enqueuePhoto(
    organizationId: string,
    userId: string,
    payload: TaskPhotoPayload,
    file: Blob,
): Promise<OfflineQueueItem<TaskPhotoPayload>> {
    return enqueue({
        kind: 'task-photo',
        payload,
        organizationId,
        userId,
        blob: file,
        status: 'queued',
    });
}

export async function enqueueSubmission(
    organizationId: string,
    userId: string,
    payload: TaskSubmissionPayload,
): Promise<OfflineQueueItem<TaskSubmissionPayload>> {
    return enqueue({
        kind: 'task-submission',
        payload,
        organizationId,
        userId,
        status: 'queued',
    });
}

/**
 * Replays every queued task-photo/task-submission item for this user,
 * oldest first, one at a time. Deliberately does NOT reuse
 * offlineQueue.replayQueued() here: that helper's `send` callback is called
 * for every 'queued' item regardless of `kind` — calling it once per kind
 * with a callback that resolves non-matching kinds as `'sent'` (an earlier
 * draft of this file did exactly that) would incorrectly mark a
 * still-unsent item of the OTHER kind as sent without ever actually
 * sending it, the moment two different kinds are queued at once. This
 * function instead lists once and dispatches each item to the right `send`
 * function by its own `kind`, in a single pass.
 *
 * Safe to call repeatedly (on every 'online' event and once at page load):
 * only items still in 'queued' status are touched, so anything already
 * 'sent'/'conflict'/'failed' from a previous pass is left alone.
 */
export async function replayMyDayQueue(
    organizationId: string,
    userId: string,
): Promise<{ sent: number; failed: number; conflict: number }> {
    const items = await listByUser(organizationId, userId);

    const queued = items
        .filter((item) => item.status === 'queued' && (item.kind === 'task-photo' || item.kind === 'task-submission'))
        .sort((a, b) => a.createdAt.localeCompare(b.createdAt));

    const result = { sent: 0, failed: 0, conflict: 0 };

    for (const item of queued) {
        await updateStatus(item.id, 'sending', { attempts: item.attempts + 1 });

        try {
            const outcome =
                item.kind === 'task-photo'
                    ? await sendPhoto(item as OfflineQueueItem<TaskPhotoPayload>)
                    : await sendSubmission(item as OfflineQueueItem<TaskSubmissionPayload>);

            await updateStatus(item.id, outcome);
            result[outcome === 'sent' ? 'sent' : outcome]++;
        } catch (error) {
            await updateStatus(item.id, 'failed', {
                lastError: error instanceof Error ? error.message : String(error),
            });
            result.failed++;
        }
    }

    return result;
}
