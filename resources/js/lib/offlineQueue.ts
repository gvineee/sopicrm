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
const DB_VERSION = 1;
const STORE_ITEMS = 'queue_items';
const STORE_BLOBS = 'blobs';

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
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

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

                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
                tx.oncomplete = () => db.close();
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

export async function enqueue<TPayload>(
    input: EnqueueInput<TPayload>,
): Promise<OfflineQueueItem<TPayload>> {
    assertNotSensitive(input.payload);

    const id = generateId();
    const timestamp = nowIso();
    let blobId: string | undefined;

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
}
