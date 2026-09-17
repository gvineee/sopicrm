/**
 * Offline queue item — see resources/js/lib/offlineQueue.ts.
 *
 * Spec section 17: drafts/photos show "ლოკალურად შენახულია" (saved locally)
 * vs "სერვერზე გაგზავნილია" (sent to server) as distinct, visible states —
 * never silently merged — and replay after reconnect must be idempotent.
 */
export type OfflineQueueItemStatus =
    | 'draft' // saved locally only, not yet queued for send
    | 'queued' // queued for send, waiting for network/replay
    | 'sending' // replay in flight
    | 'sent' // server confirmed (kept briefly for UI feedback, then pruned)
    | 'failed' // server rejected — needs user attention (e.g. version conflict, permission revoked)
    | 'conflict'; // parent record changed server-side since the draft was made

export type OfflineQueueItem<TPayload = unknown> = {
    id: string;
    /** Idempotency key sent with the request so a retried replay never double-applies. */
    idempotencyKey: string;
    /** Logical queue/table this item belongs to, e.g. "daily-journal-comment", "task-photo". */
    kind: string;
    status: OfflineQueueItemStatus;
    payload: TPayload;
    /** Optional attached blob (e.g. a captured photo) stored separately in the `blobs` store. */
    blobId?: string;
    createdAt: string;
    updatedAt: string;
    attempts: number;
    lastError?: string;
    /** Organization + user scope, so logout/user-switch can wipe only what belongs to them. */
    organizationId: string;
    userId: string;
};
