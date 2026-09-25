import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { Agent } from 'undici';
import { biostarEventCode } from './biostar-event-taxonomy.js';

/**
 * Real-adapter boundary targeting Suprema hardware — pivoted from the
 * originally-planned G-SDK gRPC Device Gateway to BioStar2's own REST API.
 * See docs/decisions.md DEC-080 for why: the G-SDK Node.js client is a
 * manual, account-gated download (not an npm package) that was not
 * available, whereas BioStar2's REST API was live-verified against a real
 * XPass 2 reader (device/user/event endpoints all confirmed working) with
 * zero new dependencies — plain HTTPS + JSON, using Node's built-in fetch.
 *
 * Auth: POST /api/login returns a `bs-session-id` header, sent back as a
 * request header on every subsequent call (re-acquired once on a 401).
 *
 * WRITE PATH SCOPE (see docs/decisions.md DEC-082): `applyCommand()`
 * implements `add_user` (create/update the BioStar user record — name,
 * user_id, user_group_id, validity window) against Suprema's officially
 * documented `POST /api/users` / `PUT /api/users/:id` shape. It deliberately
 * does NOT implement card enrollment (`POST /api/cards`) yet — Suprema's
 * public docs confirm the field names (card_type, card_id) but not the
 * literal top-level JSON wrapper key, and guessing that wrong against a
 * live production door reader is worse than leaving it unimplemented and
 * loud. Any other command type is rejected, not silently ignored.
 *
 * BIO-01 READ-ONLY GATE: regardless of the above, `applyCommand()` refuses
 * every command type — including `add_user` — unless `BIOSTAR_WRITE_DISPATCH_ENABLED`
 * is the literal string `true` (README.md has the full table). This is the
 * first integration stage's intended state: BioStar owns devices/cards/
 * access, the CRM only reads. The Laravel side independently withholds
 * write commands from ever reaching this connector's `/commands` poll
 * (App\Http\Controllers\Api\V1\Devices\ConnectorCommandController), so
 * real-BioStar write dispatch requires both boundaries to be deliberately
 * turned on, not just one.
 *
 * BIO-04: TLS trust and timeouts are scoped to THIS adapter's own requests
 * via a dedicated `undici` `Agent` passed as `dispatcher` on every fetch
 * call — never the process-wide `NODE_TLS_REJECT_UNAUTHORIZED=0` mutation
 * this class used before, which would have silently disabled certificate
 * verification for every other outbound HTTPS call this process makes
 * (including to Laravel itself). An operator can now instead point
 * `BIOSTAR_CA_CERT_PATH` at the BioStar server's own self-signed
 * certificate to trust that one certificate specifically, which is the
 * correct fix for the common "self-signed LAN deployment" case
 * `BIOSTAR_VERIFY_TLS=false` was previously the only escape hatch for.
 */
/**
 * How far the reader's own clock is from the server's, in seconds. Reported
 * so Laravel's clock-drift detection sees the real number instead of
 * inferring it, and so a device whose clock is wrong is visible as a fact
 * rather than as strange attendance.
 */
function clockOffsetSeconds(row) {
    if (!row?.datetime || !row?.server_datetime) return null;

    const device = Date.parse(row.datetime);
    const server = Date.parse(row.server_datetime);

    if (Number.isNaN(device) || Number.isNaN(server)) return null;

    const offset = Math.round((device - server) / 1000);

    // The Laravel side validates this between -86400 and 86400; a value
    // outside that says the clock is not merely drifting, and clamping it
    // would hide that.
    return Math.abs(offset) <= 86400 ? offset : null;
}

export class SupremaDeviceGatewayAdapter {
    mode = 'suprema';
    label = 'BioStar2 REST (Suprema) — read-only, hardware-verified for reads only';

    #config;
    #fetch;
    #dispatcher;
    #sessionId = null;

    /**
     * BioStar user_id -> the card that user carries. Events name the PERSON
     * but never the card, while the CRM matches a swipe to an employee by
     * card — so without this every imported event would arrive with no
     * credential and no employee, and produce no attendance at all.
     *
     * Cached because it is a per-person fact that changes when somebody is
     * issued a card, not per swipe.
     */
    #cardsByUserId = new Map();

    #cardsLoadedAt = 0;

    constructor(env = process.env, fetchImpl = globalThis.fetch) {
        this.#config = this.#readConfig(env);
        this.#fetch = fetchImpl;
        this.#dispatcher = new Agent({
            connect: {
                rejectUnauthorized: this.#config.verifyTls,
                ca: this.#config.caCert ?? undefined,
            },
        });
    }

    /**
     * @returns {{ baseUrl: string, username: string, password: string, verifyTls: boolean, caCert: string|null, requestTimeoutMs: number, defaultUserGroupId: string, defaultUserGroupName: string, writeDispatchEnabled: boolean }}
     */
    #readConfig(env) {
        const baseUrl = (env.BIOSTAR_BASE_URL || '').replace(/\/$/, '');
        const username = env.BIOSTAR_USERNAME || '';
        const password = env.BIOSTAR_PASSWORD || '';
        // BioStar2 ships with a self-signed certificate by default on a LAN
        // deployment — verification defaults ON; an operator must opt out
        // explicitly (and knowingly) rather than this silently disabling it.
        // Prefer BIOSTAR_CA_CERT_PATH (trusts that one certificate) over
        // this (trusts none) whenever the server's own cert is available.
        const verifyTls = env.BIOSTAR_VERIFY_TLS !== 'false';
        const caCertPath = env.BIOSTAR_CA_CERT_PATH || '';
        let caCert = null;
        if (caCertPath !== '') {
            try {
                caCert = readFileSync(caCertPath, 'utf8');
            } catch (error) {
                throw new Error(`BIOSTAR_CA_CERT_PATH is set to '${caCertPath}' but could not be read: ${error instanceof Error ? error.message : String(error)}`);
            }
        }
        const requestTimeoutMs = Number(env.BIOSTAR_REQUEST_TIMEOUT_MS || 10_000);
        // BioStar2 always has a built-in "All Users" group (id 1) — see the
        // live GET /api/user_groups response this was confirmed against.
        const defaultUserGroupId = env.BIOSTAR_DEFAULT_USER_GROUP_ID || '1';
        const defaultUserGroupName = env.BIOSTAR_DEFAULT_USER_GROUP_NAME || 'All Users';
        // BIO-01: same name/semantics as Laravel's `devices.biostar_write_dispatch_enabled`
        // — defaults to disabled (read-only) unless explicitly set to the
        // literal string "true".
        const writeDispatchEnabled = env.BIOSTAR_WRITE_DISPATCH_ENABLED === 'true';

        const missing = [];
        if (!baseUrl) missing.push('BIOSTAR_BASE_URL');
        if (!username) missing.push('BIOSTAR_USERNAME');
        if (!password) missing.push('BIOSTAR_PASSWORD');
        if (missing.length > 0) {
            throw new Error(
                `Suprema mode is missing required configuration: ${missing.join(', ')}. `
                + 'Set these once the BioStar2 server is reachable, or use DEVICE_CONNECTOR_MODE=simulator until then.',
            );
        }

        return { baseUrl, username, password, verifyTls, caCert, requestTimeoutMs, defaultUserGroupId, defaultUserGroupName, writeDispatchEnabled };
    }

    /**
     * Every outbound call goes through here so the scoped `dispatcher` and
     * request timeout apply uniformly — no fetch call anywhere in this class
     * is allowed to skip either.
     * @returns {Promise<Response>}
     */
    #fetchWithTimeout(url, options = {}) {
        return this.#fetch(url, {
            ...options,
            dispatcher: this.#dispatcher,
            signal: AbortSignal.timeout(this.#config.requestTimeoutMs),
        });
    }

    async #login() {
        let response;
        try {
            response = await this.#fetchWithTimeout(`${this.#config.baseUrl}/api/login`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ User: { login_id: this.#config.username, password: this.#config.password } }),
            });
        } catch (error) {
            // Never let a timeout/network error surface the login body
            // (contains the password) — only the error's own message, which
            // for an AbortError/TypeError never includes request contents.
            throw new Error(`BioStar2 login request failed: ${error instanceof Error ? error.message : String(error)}`);
        }
        const sessionId = response.headers.get('bs-session-id');
        if (!response.ok || !sessionId) {
            throw new Error(`BioStar2 login failed: HTTP ${response.status}`);
        }
        this.#sessionId = sessionId;
    }

    /**
     * BioStar reports a card id in DECIMAL (`card_id: "69410222"`), while the
     * CRM's ingest endpoint expects hex and converts it back to decimal for
     * `canonical_identifier`. Sending the decimal string through unchanged
     * would be read as hex — 69410222 decimal would arrive as 1765868066 —
     * which silently matches no credential and files a triage row for a card
     * nobody owns. Verified both directions against the live server.
     */
    static decimalCardIdToHex(cardId) {
        if (cardId === null || cardId === undefined) return null;

        const digits = String(cardId).trim();
        if (!/^\d+$/.test(digits)) return null;

        return BigInt(digits).toString(16).toUpperCase();
    }

    /**
     * Refreshes the user -> card map. The list endpoint reports only a
     * `card_count`, so the cards themselves come from each holder's detail
     * record; only users who actually have one are fetched.
     */
    async #refreshCardCache(ttlMs = 300000) {
        if (Date.now() - this.#cardsLoadedAt < ttlMs && this.#cardsByUserId.size > 0) return;

        const list = await this.#request('/api/users?limit=1000');
        const rows = list?.UserCollection?.rows ?? [];
        const next = new Map();

        for (const row of rows) {
            if (Number(row.card_count ?? 0) < 1) continue;

            try {
                const detail = await this.#request(`/api/users/${row.user_id}`);
                const card = detail?.User?.cards?.find((c) => c.is_assigned !== 'false' && c.is_blocked !== 'true')
                    ?? detail?.User?.cards?.[0];

                const hex = SupremaDeviceGatewayAdapter.decimalCardIdToHex(card?.card_id);
                if (!hex) continue;

                next.set(String(row.user_id), {
                    card_type: card?.card_type?.name ?? 'CSN',
                    card_hex: hex,
                });
            } catch {
                // One unreadable user must not cost the whole import. The
                // event still imports; it simply arrives unmatched and shows
                // up on the unknown-cards triage page, which is the honest
                // outcome rather than a guess.
            }
        }

        if (next.size > 0 || rows.length === 0) {
            this.#cardsByUserId = next;
            this.#cardsLoadedAt = Date.now();
        }
    }

    async #request(path, { method = 'GET', body } = {}) {
        if (!this.#sessionId) await this.#login();

        const doFetch = () => this.#fetchWithTimeout(`${this.#config.baseUrl}${path}`, {
            method,
            headers: { 'bs-session-id': this.#sessionId, 'Content-Type': 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        let response = await doFetch();
        if (response.status === 401) {
            // Session expired — re-authenticate once, then retry.
            await this.#login();
            response = await doFetch();
        }

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = data?.Response?.message || `HTTP ${response.status}`;
            throw new Error(`BioStar2 REST request to ${path} failed: ${message}`);
        }
        return data;
    }

    /**
     * Device API equivalent: live capability/status read, never hardcoded.
     * @param {string} deviceId
     */
    async heartbeat(deviceId) {
        const data = await this.#request(`/api/devices/${encodeURIComponent(deviceId)}`);
        const device = data?.Device;
        if (!device) throw new Error(`BioStar2 returned no Device for id ${deviceId}`);

        return {
            // BioStar2 status "1" observed on a real connected XPass 2 as a
            // JSON string; compared via String() rather than a bare `===`
            // so a firmware/API variant that instead returns it as a JSON
            // number (`1`) doesn't silently flip a genuinely online device
            // to "offline" on a type technicality — exact enum still not
            // otherwise guessed at (anything but "1" stays offline).
            status: String(device.status) === '1' ? 'online' : 'offline',
            // Top-level, not just inside `capabilities` — StoreConnectorHeartbeatRequest
            // reads firmware_version as its own field (App\Http\Controllers\Api\V1\Devices\ConnectorHeartbeatController).
            firmware_version: device.version?.firmware ?? null,
            capabilities: {
                product_name: device.version?.product_name ?? null,
                firmware: device.version?.firmware ?? null,
                use_em: device.card?.use_em === 'true',
                use_mifare: device.card?.use_mifare_felica === 'true',
                use_csn: device.card?.use_csn === 'true',
            },
        };
    }

    /**
     * `add_user`: create-or-update the BioStar user record for an
     * employee's credential issuance (App\Domain\Devices\Actions\
     * IssueCredentialAction). Idempotent by design — checks for an existing
     * user by id first, since ProcessDeviceSyncCommandAction can retry the
     * same logical command on a transient failure. Card enrollment is NOT
     * done here (see class docblock) — this only ensures the person exists
     * as a BioStar user in the default group.
     *
     * @param {string} deviceId
     * @param {{ type: string, payload: Record<string, unknown> }} command
     */
    async applyCommand(deviceId, command) {
        // BIO-01 adapter-side boundary: the server (ConnectorCommandController)
        // is expected to withhold write commands from a real BioStar device
        // in read-only mode, but this check exists so the adapter refuses on
        // its own too — no HTTP request is made to BioStar for any write
        // command unless this is explicitly turned on, even if a
        // misconfigured or older server handed one over anyway. Must be kept
        // in sync with `devices.biostar_write_dispatch_enabled` in the
        // Laravel config (same env var name, checked independently).
        if (this.#config.writeDispatchEnabled !== true) {
            return {
                result: 'failed',
                error: `BioStar write dispatch is disabled (read-only mode) — command type "${command.type}" was not sent to the device. `
                    + 'Cards and access are managed directly in BioStar until a write integration is pilot-confirmed.',
            };
        }

        if (command.type !== 'add_user') {
            return { result: 'failed', error: `Unsupported command type for BioStar2 REST: ${command.type}` };
        }

        const payload = command.payload || {};
        const rawId = String(payload.employee_internal_code || payload.employee_id || '');
        if (!rawId) {
            return { result: 'failed', error: 'add_user payload is missing employee_internal_code/employee_id' };
        }
        // HYPOTHESIS, not yet live-confirmed (see docs/decisions.md DEC-082
        // addendum): every user_id this session has observed on the real
        // server ("1", "2") and in every official Suprema example ("108",
        // "99999") is purely numeric, despite the field being JSON-typed as
        // a string — a live test with the alphanumeric id
        // "CLAUDE-TEST-001" was rejected with "Invalid Parameters" even
        // after fixing the user_group_id type bug below, which is
        // consistent with (but does not prove) a numeric-only constraint.
        // A human-readable internal_code that's already purely numeric is
        // used as-is; anything else is deterministically hashed to a
        // numeric id instead of sent raw.
        const userId = /^\d+$/.test(rawId) ? rawId : this.#deriveNumericUserId(rawId);
        const name = typeof payload.employee_name === 'string' && payload.employee_name.trim() !== ''
            ? payload.employee_name.trim()
            : userId;

        const user = {
            name,
            user_id: userId,
            // Suprema's own official POST /api/users example shows
            // user_group_id.id as a bare JSON number (`"id": 1`), not a
            // quoted string — live-confirmed as the cause of a real
            // "Invalid Parameters" rejection when this was sent as a string.
            user_group_id: { id: Number(this.#config.defaultUserGroupId), name: this.#config.defaultUserGroupName },
            disabled: 'false',
            start_datetime: '2001-01-01T00:00:00.00Z',
            expiry_datetime: '2037-12-31T23:59:00.00Z',
        };

        try {
            const existing = await this.#tryGetUser(userId);
            if (existing) {
                await this.#request(`/api/users/${encodeURIComponent(userId)}`, { method: 'PUT', body: { User: user } });
            } else {
                await this.#request('/api/users', { method: 'POST', body: { User: user } });
            }
            return { result: 'succeeded', deviceEcho: { user_id: userId, updated: Boolean(existing) } };
        } catch (error) {
            // Unreachable/5xx is retryable; a validation rejection (4xx,
            // already surfaced as a thrown Error with BioStar's own message)
            // is not — but without parsing BioStar's specific error codes
            // yet, treat every failure here as retryable rather than
            // guessing wrong and dead-lettering a command that would have
            // succeeded on the next attempt.
            return { result: 'retry', error: error instanceof Error ? error.message : String(error) };
        }
    }

    /**
     * Deterministic: the same input always maps to the same numeric id, so
     * this stays idempotent across retries and across our own
     * add_user -> #tryGetUser round-trips. Prefixed with "9" to keep well
     * clear of the low, hand-assigned ids already seen on the real server
     * ("1", "2") and of anything a human might type in the BioStar admin
     * UI directly.
     */
    #deriveNumericUserId(rawId) {
        const digest = createHash('sha256').update(rawId).digest('hex');
        const numeric = BigInt(`0x${digest.slice(0, 12)}`) % 100_000_000n;
        return `9${numeric.toString().padStart(8, '0')}`;
    }

    /**
     * BioStar2 does NOT use HTTP 404 for "not found" — confirmed live
     * against the real server: GET /api/users/{unknown-id} returns HTTP 400
     * with body {"Response":{"code":"201","message":"User can not be found
     * with id"}}. Its error signaling lives in the JSON body's `Response`
     * object, not the HTTP status line, so this checks the body's message
     * rather than assuming any particular status code means "missing."
     * @returns {Promise<Record<string, unknown>|null>}
     */
    async #tryGetUser(userId) {
        if (!this.#sessionId) await this.#login();

        const response = await this.#fetchWithTimeout(`${this.#config.baseUrl}/api/users/${encodeURIComponent(userId)}`, {
            headers: { 'bs-session-id': this.#sessionId },
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const message = data?.Response?.message ?? '';
            if (/can\s*not\s*be\s*found|cannot\s*be\s*found/i.test(message)) return null;
            throw new Error(`BioStar2 lookup for user ${userId} failed: HTTP ${response.status} ${message}`);
        }

        return data?.User ?? null;
    }

    /**
     * Event API equivalent, via BioStar2's event log search.
     *
     * Two things here were verified against a live BioStar 2 server rather
     * than assumed, and the code reflects what was actually confirmed:
     *
     *  - A server-side `device_id` condition DOES work. This used to pull a
     *    bounded window across ALL devices and filter in JS, which on a busy
     *    install silently lost events: a chatty door's lock/unlock traffic
     *    could fill the whole window before a quiet reader's badge read was
     *    reached. The window is now per device, so one device's noise cannot
     *    crowd out another's.
     *  - A time-range condition could NOT be confirmed. Several operator
     *    values return rows without demonstrably narrowing anything, and
     *    guessing wrong here means silently dropping real events, so the
     *    incremental cut is still made on `native_event_id` — which BioStar
     *    guarantees monotonic — rather than on a date filter that might not
     *    be doing what it appears to.
     *
     *  If more than `limit` events land on ONE device between polls, some can
     *  still be missed; Laravel's checkpoint/data-gap anomaly detection
     *  (IngestRawAccessEventAction) flags that rather than losing it quietly.
     *
     * @param {string} deviceId
     * @param {{ lastNativeEventId?: number }} checkpoint
     * @param {number} limit
     */
    async pullEvents(deviceId, checkpoint, limit) {
        // Events name the person but never the card; the CRM matches a swipe
        // to an employee BY card. Without this the import would land every
        // event with no credential and produce no attendance at all.
        await this.#refreshCardCache().catch(() => {});

        const windowSize = Math.max(limit, 200);
        const data = await this.#request('/api/events/search', {
            method: 'POST',
            body: {
                Query: {
                    limit: windowSize,
                    // Verified working against a live server: this really does
                    // narrow the result to one device.
                    conditions: [{ column: 'device_id', operator: 0, values: [String(deviceId)] }],
                    orders: [{ column: 'datetime', descending: true }],
                },
            },
        });
        const rows = data?.EventCollection?.rows ?? [];
        const sinceId = checkpoint?.lastNativeEventId ?? 0;

        return rows
            // Defence in depth: the condition above is server-side, but a row
            // for another device must never be attributed to this one.
            .filter((row) => String(row?.device_id?.id) === String(deviceId))
            .map((row) => {
                const card = this.#cardsByUserId.get(String(row?.user_id?.user_id ?? ''));

                return {
                    native_event_id: Number(row.id),
                // BioStar2's own event log is durably unique/monotonic — it
                // already absorbs any device-side log rollover internally, so
                // there is no separate "stream epoch" to track on this path.
                    stream_epoch: 0,
                // What the READER believed the time was. On the live install
                // this runs three hours behind real UTC, which is exactly why
                // it is no longer what attendance computes from.
                    raw_device_time: row.datetime,
                // What the SERVER recorded, which matched real UTC exactly.
                // Laravel stores this as `normalized_event_time_utc` and
                // keeps the device's claim beside it, so a drifting clock
                // stays visible instead of being quietly corrected away.
                    server_time: row.server_datetime ?? null,
                    clock_offset_seconds: clockOffsetSeconds(row),
                    // BioStar's own id for the person. A card can be reissued;
                    // this does not change, so it is the durable anchor for a
                    // BioStar-person -> CRM-employee link.
                    external_user_ref: row?.user_id?.user_id ? String(row.user_id.user_id) : null,
                // Mapped, not forwarded raw: the attendance rebuild excludes
                // `access_denied`, and an unmapped `biostar:6401` would have
                // sailed past that exclusion and counted a refused badge as
                // an arrival.
                    event_code: biostarEventCode(row.event_type_id?.code),
                    payload: row,
                    // The card the person who swiped carries, converted from
                    // BioStar's decimal to the hex the CRM's normalizer
                    // expects. Absent when the holder has no card on file —
                    // the event still imports and shows up for triage rather
                    // than being attributed to a guess.
                    ...(card ? { card_type: card.card_type, card_hex: card.card_hex } : {}),
                    // Laravel's StoreConnectorEventsRequest accepts
                    // 'device-connector' | 'simulator' | 'biostar-import'.
                    ingestion_source: 'biostar-import',
                };
            })
            .filter((event) => event.native_event_id > sinceId)
            .sort((a, b) => a.native_event_id - b.native_event_id)
            .slice(0, limit);
    }

}
