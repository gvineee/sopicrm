import type { OfflineQueueItem, OfflineQueueItemStatus } from '@/types';

/**
 * IndexedDB-backed offline queue primitive (spec section 17, task item 5).
 *
 * Scope and safety rules a later module MUST keep when it starts writing
 * real drafts through this:
 *  - Never store salary amounts or personal-ID numbers here by default
 *    (hard constraint + spec 17 "ნაგულისხმევად ხელფასები/პირადი ნომრები
 *    offline არ შეინახო"). `assertNotSensitive()` below is a best-effort
 *    guard that throws in dev if an obviously-named sensitive key is
 *    present on a payload; it is a safety net, not a substitute for each
 *    module reviewing what it puts in `payload`.
 *  - `clearProtectedData()` must run on logout and on user-switch so one
 *    person's drafts/photos never leak into the next session on a shared
 *    device (spec 17 "Logout-ზე გაასუფთავე დაცული cache").
 *  - Replay is idempotent: every item carries its own `idempotencyKey`
 *    (see docs/decisions.md DEC-015 for the matching server-side contract)
 *    so a retried send after a flaky network never double-applies.
 *
 * No IndexedDB wrapper library was added (see docs/decisions.md DEC-047) —
 * this file is deliberately small and dependency-free.
 */

const DB_NAME = 'oda-crm-offline';
const DB_VERSION = 2;
const STORE_ITEMS = 'queue_items';
const STORE_BLOBS = 'blobs';
const STORE_TASK_CACHE = 'task_list_cache';

// REQ-NTF-09: a hard cap on how many not-yet-resolved items one user's queue
// may hold at once. Bounded so a device offline for many days doesn't
// accumulate unlimited local storage pressure before a signal recovers —
// a plain count of pending items (not bytes) is simple, testable, and
// matches this file's own "small, dependency-free" design preference.
// `enqueue()` refuses a new item past this cap with an honest error rather
// than silently accepting it and losing it later.
export const MAX_PENDING_QUEUE_ITEMS = 50;

/**
 * Thrown by `enqueue()` when the browser's own storage quota is exhausted
 * (a real `DOMException` named `QuotaExceededError` — the standard error
 * IndexedDB throws for this, not a made-up type). REQ-NTF-05: the caller
 * must show an honest, actionable message and must NEVER report the item
 * as saved when this is thrown.
 */
export class OfflineQueueQuotaExceededError extends Error {
    constructor() {
        super('IndexedDB storage quota exceeded while saving an offline item.');
        this.name = 'OfflineQueueQuotaExceededError';
    }
}

/**
 * Thrown by `enqueue()` when the user's own pending-item count would exceed
 * `MAX_PENDING_QUEUE_ITEMS`. REQ-NTF-09.
 */
export class OfflineQueueFullError extends Error {
    constructor(public readonly limit: number) {
        super(`Offline queue is full (limit: ${limit} pending items).`);
        this.name = 'OfflineQueueFullError';
    }
}

// Field-name deny-list used only as a development-time safety net — see the
// docblock above. Extend cautiously; this is not the enforcement point.
const SENSITIVE_KEY_PATTERN =
    /(salary|wage|payroll|net_pay|gross_pay|personal_id|national_id|ssn|iban|card_number)/i;

function assertNotSensitive(value: unknown, path = ''): void {
    if (!import.meta.env.DEV) {
        return;
    }

    if (value === null || typeof value !== 'object') {
        return;
    }

    for (const [key, nested] of Object.entries(
        value as Record<string, unknown>,
    )) {
        const nextPath = path ? `${path}.${key}` : key;

        if (SENSITIVE_KEY_PATTERN.test(key)) {
            throw new Error(
                `offlineQueue: refusing to persist field "${nextPath}" — looks like salary/personal-ID data, which must never be cached offline by default (spec section 17).`,
            );
        }

        assertNotSensitive(nested, nextPath);
    }
}

function openDb(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined') {
            reject(new Error('IndexedDB is not available in this context.'));
            return;
        }

        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(STORE_ITEMS)) {
                const store = db.createObjectStore(STORE_ITEMS, {
                    keyPath: 'id',
                });
                store.createIndex('by_status', 'status');
                store.createIndex('by_kind', 'kind');
                store.createIndex('by_user', 'userId');
                store.createIndex('by_org', 'organizationId');
            }

            if (!db.objectStoreNames.contains(STORE_BLOBS)) {
                db.createObjectStore(STORE_BLOBS, { keyPath: 'id' });
            }

            // REQ-NTF-04: one row per (organizationId, userId) — a bounded
            // read-only snapshot of the user's own My Day task list, so the
            // page has something real to render if it's opened (or
            // reopened) while offline, before any submission ever happens.
            // This is reference data only: nothing ever submits AGAINST it,
            // and it is never treated as authoritative once a real fetch
            // succeeds again.
            if (!db.objectStoreNames.contains(STORE_TASK_CACHE)) {
                db.createObjectStore(STORE_TASK_CACHE, { keyPath: 'id' });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/**
 * Resolves on `tx.oncomplete`, NOT `request.onsuccess` — the request event
 * fires the instant the individual operation is queued/applied in memory,
 * which is BEFORE the browser has actually durably committed the
 * transaction to disk. Resolving on `request.onsuccess` (the previous,
 * buggy behavior here) meant the UI could be told "your draft is saved" a
 * moment before it was actually durable: if the browser/tab was killed in
 * that narrow window — exactly PWA-01's own "app restart" acceptance
 * scenario — the draft could silently vanish despite having been
 * confirmed. `tx.oncomplete` is the real, guaranteed-durable commit signal;
 * the request's own result is captured when it fires and only handed back
 * once that commit actually happens.
 */
function runInStore<T>(
    storeName: string,
    mode: IDBTransactionMode,
    fn: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
    return openDb().then(
        (db) =>
            new Promise<T>((resolve, reject) => {
                const tx = db.transaction(storeName, mode);
                const store = tx.objectStore(storeName);
                const request = fn(store);
                let result: T;

                request.onsuccess = () => {
                    result = request.result;
                };
                request.onerror = () => reject(request.error);
                tx.oncomplete = () => {
                    db.close();
                    resolve(result);
                };
                tx.onerror = () => reject(tx.error);
                tx.onabort = () => reject(tx.error ?? new Error('IndexedDB transaction aborted.'));
            }),
    );
}

function nowIso(): string {
    return new Date().toISOString();
}

function generateId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export type EnqueueInput<TPayload> = {
    kind: string;
    payload: TPayload;
    organizationId: string;
    userId: string;
    blob?: Blob;
    status?: OfflineQueueItemStatus;
};

/**
 * @throws {OfflineQueueFullError} the user's own pending count is already
 *   at `MAX_PENDING_QUEUE_ITEMS` — refused before anything is written, so
 *   the caller never has to guess whether a partial write happened.
 * @throws {OfflineQueueQuotaExceededError} the browser's storage quota is
 *   exhausted — the caller must show this as an honest failure, never as
 *   a saved draft.
 */
export async function enqueue<TPayload>(
    input: EnqueueInput<TPayload>,
): Promise<OfflineQueueItem<TPayload>> {
    assertNotSensitive(input.payload);

    const pendingCount = await countPending(input.organizationId, input.userId);
    if (pendingCount >= MAX_PENDING_QUEUE_ITEMS) {
        throw new OfflineQueueFullError(MAX_PENDING_QUEUE_ITEMS);
    }

    const id = generateId();
    const timestamp = nowIso();
    let blobId: string | undefined;

    try {
        if (input.blob) {
            blobId = `${id}-blob`;
            await runInStore(STORE_BLOBS, 'readwrite', (store) =>
                store.put({ id: blobId, blob: input.blob }),
            );
        }

        const item: OfflineQueueItem<TPayload> = {
            id,
            idempotencyKey: generateId(),
            kind: input.kind,
            status: input.status ?? 'draft',
            payload: input.payload,
            blobId,
            createdAt: timestamp,
            updatedAt: timestamp,
            attempts: 0,
            organizationId: input.organizationId,
            userId: input.userId,
        };

        await runInStore(STORE_ITEMS, 'readwrite', (store) => store.put(item));

        return item;
    } catch (error) {
        if (isQuotaExceededError(error)) {
            throw new OfflineQueueQuotaExceededError();
        }

        throw error;
    }
}

function isQuotaExceededError(error: unknown): boolean {
    return (
        (error instanceof DOMException && error.name === 'QuotaExceededError') ||
        (error instanceof Error && error.name === 'QuotaExceededError')
    );
}

/**
 * How many of this user's own items are still pending (not yet resolved
 * one way or the other) — the count `enqueue()`'s `MAX_PENDING_QUEUE_ITEMS`
 * cap is measured against. `sent`/`failed`/`conflict` items don't count:
 * they're resolved history, not open queue pressure (a `failed` item is
 * still retriable, but retrying goes through `replayQueued()` against an
 * EXISTING row, never a fresh `enqueue()` call).
 */
async function countPending(organizationId: string, userId: string): Promise<number> {
    const items = await listByUser(organizationId, userId);

    return items.filter((item) =>
        ['draft', 'queued', 'sending'].includes(item.status),
    ).length;
}

export async function updateStatus(
    id: string,
    status: OfflineQueueItemStatus,
    patch: Partial<Pick<OfflineQueueItem, 'attempts' | 'lastError'>> = {},
): Promise<void> {
    const db = await openDb();

    await new Promise<void>((resolve, reject) => {
        const tx = db.transaction(STORE_ITEMS, 'readwrite');
        const store = tx.objectStore(STORE_ITEMS);
        const getRequest = store.get(id);

        getRequest.onsuccess = () => {
            const existing = getRequest.result as OfflineQueueItem | undefined;

            if (!existing) {
                resolve();
                return;
            }

            store.put({
                ...existing,
                ...patch,
                status,
                updatedAt: nowIso(),
            });
        };
        getRequest.onerror = () => reject(getRequest.error);
        tx.oncomplete = () => {
            db.close();
            resolve();
        };
        tx.onerror = () => reject(tx.error);
    });
}

export async function listByUser(
    organizationId: string,
    userId: string,
): Promise<OfflineQueueItem[]> {
    const all = await runInStore<OfflineQueueItem[]>(
        STORE_ITEMS,
        'readonly',
        (store) => store.getAll() as IDBRequest<OfflineQueueItem[]>,
    );

    return all.filter(
        (item) =>
            item.organizationId === organizationId && item.userId === userId,
    );
}

export async function getBlob(blobId: string): Promise<Blob | null> {
    const record = await runInStore<{ id: string; blob: Blob } | undefined>(
        STORE_BLOBS,
        'readonly',
        (store) =>
            store.get(blobId) as IDBRequest<
                { id: string; blob: Blob } | undefined
            >,
    );

    return record?.blob ?? null;
}

export async function remove(id: string): Promise<void> {
    const item = await runInStore<OfflineQueueItem | undefined>(
        STORE_ITEMS,
        'readonly',
        (store) => store.get(id) as IDBRequest<OfflineQueueItem | undefined>,
    );

    await runInStore(STORE_ITEMS, 'readwrite', (store) => store.delete(id));

    if (item?.blobId) {
        await runInStore(STORE_BLOBS, 'readwrite', (store) =>
            store.delete(item.blobId as string),
        );
    }
}

/**
 * Replays every 'queued' item for the given user, oldest first, calling
 * `send` once per item. `send` must itself be idempotent server-side (it
 * should pass `item.idempotencyKey` as the request's Idempotency-Key
 * header — see docs/decisions.md DEC-015) because a dropped connection
 * mid-replay can cause this function to retry the same item on the next
 * "online" event.
 */
export async function replayQueued<TPayload>(
    organizationId: string,
    userId: string,
    send: (
        item: OfflineQueueItem<TPayload>,
    ) => Promise<'sent' | 'conflict' | 'failed'>,
): Promise<{ sent: number; failed: number; conflict: number }> {
    const items = (await listByUser(
        organizationId,
        userId,
    )) as OfflineQueueItem<TPayload>[];

    const queued = items
        .filter((item) => item.status === 'queued')
        .sort((a, b) => a.createdAt.localeCompare(b.createdAt));

    const result = { sent: 0, failed: 0, conflict: 0 };

    for (const item of queued) {
        await updateStatus(item.id, 'sending', { attempts: item.attempts + 1 });

        try {
            const outcome = await send(item);
            await updateStatus(item.id, outcome);
            result[outcome === 'sent' ? 'sent' : outcome]++;
        } catch (error) {
            await updateStatus(item.id, 'failed', {
                lastError:
                    error instanceof Error ? error.message : String(error),
            });
            result.failed++;
        }
    }

    return result;
}

/**
 * Wipes every locally-stored draft/photo for one user in one organization.
 * Call this on logout and on user-switch (spec 17 hard requirement) — never
 * on app update, since an update must NOT destroy an unsent draft.
 */
export async function clearProtectedData(
    organizationId: string,
    userId: string,
): Promise<void> {
    const items = await listByUser(organizationId, userId);

    for (const item of items) {
        await remove(item.id);
    }

    await clearCachedTaskList(organizationId, userId);
}

export type CachedTaskSummary = {
    id: string;
    title: string;
    projectName: string | null;
    status: string;
    dueAt: string | null;
    bucket: string;
};

type TaskListCacheRecord = {
    id: string;
    organizationId: string;
    userId: string;
    tasks: CachedTaskSummary[];
    cachedAt: string;
};

function taskCacheKey(organizationId: string, userId: string): string {
    return `${organizationId}:${userId}`;
}

/**
 * REQ-NTF-04: stores a bounded, read-only snapshot of the user's own My Day
 * task list — called after a REAL successful server fetch only, never from
 * a queued/replayed submission. `assertNotSensitive()` applies here too:
 * this must stay just enough to render the same buckets (title/project/
 * status/due date), never a salary/personal-ID field.
 */
export async function cacheTaskList(
    organizationId: string,
    userId: string,
    tasks: CachedTaskSummary[],
): Promise<void> {
    assertNotSensitive(tasks);

    const record: TaskListCacheRecord = {
        id: taskCacheKey(organizationId, userId),
        organizationId,
        userId,
        tasks,
        cachedAt: nowIso(),
    };

    await runInStore(STORE_TASK_CACHE, 'readwrite', (store) => store.put(record));
}

/**
 * Read-only lookup for rendering My Day while offline (or while a fresh
 * fetch has failed). Returns `null` when nothing has ever been cached for
 * this user yet (a genuinely new device/session, not "confirmed empty" —
 * the caller must render its own honest empty state for that case, never
 * pretend a cache existed).
 */
export async function getCachedTaskList(
    organizationId: string,
    userId: string,
): Promise<{ tasks: CachedTaskSummary[]; cachedAt: string } | null> {
    const record = await runInStore<TaskListCacheRecord | undefined>(
        STORE_TASK_CACHE,
        'readonly',
        (store) =>
            store.get(taskCacheKey(organizationId, userId)) as IDBRequest<
                TaskListCacheRecord | undefined
            >,
    );

    return record ? { tasks: record.tasks, cachedAt: record.cachedAt } : null;
}

async function clearCachedTaskList(organizationId: string, userId: string): Promise<void> {
    await runInStore(STORE_TASK_CACHE, 'readwrite', (store) =>
        store.delete(taskCacheKey(organizationId, userId)),
    );
}
