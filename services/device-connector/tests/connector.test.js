import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { SimulatorAdapter } from '../src/adapters/simulator.js';
import { SupremaDeviceGatewayAdapter } from '../src/adapters/suprema-device-gateway.js';
import { LaravelConnectorClient } from '../src/laravel-client.js';
import { createConnector } from '../src/index.js';

test('Laravel client sends token, replay headers and idempotency key', async () => {
    let captured;
    const client = new LaravelConnectorClient({ baseUrl: 'https://laravel.test/api/v1/device-connector/', token: 'secret-token', connectorVersion: '1.0.0', fetchImpl: async (url, options) => { captured = { url, options }; return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } }); } });
    await client.heartbeat('device-1', { status: 'online', capabilities: { simulated: true } });
    assert.equal(captured.options.headers.Authorization, 'Bearer secret-token');
    assert.match(captured.options.headers['X-Connector-Nonce'], /^[0-9a-f-]{36}$/);
    assert.match(captured.options.headers['X-Connector-Timestamp'], /^\d+$/);
    assert.match(captured.options.headers['Idempotency-Key'], /^heartbeat:device-1:/);
});

test('simulator rejects a stale version after a newer command applied', async () => {
    const adapter = new SimulatorAdapter(); const base = { targetEntityType: 'credential-assignment', targetEntityId: 'a' };
    assert.equal((await adapter.applyCommand('device-1', { ...base, commandVersion: 2 })).result, 'succeeded');
    const stale = await adapter.applyCommand('device-1', { ...base, commandVersion: 1 });
    assert.equal(stale.result, 'failed'); assert.match(stale.error, /stale command/);
});

test('simulator drains bounded event batches without ambient events', async () => {
    const adapter = new SimulatorAdapter(); const checkpoint = { streamEpoch: 0, lastNativeEventId: 0 };
    assert.deepEqual(await adapter.pullEvents('device-1', checkpoint, 10), []);
    adapter.queueEvent('device-1', { native_event_id: 1, stream_epoch: 0 }); adapter.queueEvent('device-1', { native_event_id: 2, stream_epoch: 0 });
    assert.equal((await adapter.pullEvents('device-1', checkpoint, 1)).length, 1); assert.equal((await adapter.pullEvents('device-1', checkpoint, 10)).length, 1);
});

const BIOSTAR_ENV = { BIOSTAR_BASE_URL: 'https://biostar.test', BIOSTAR_USERNAME: 'admin', BIOSTAR_PASSWORD: 'secret' };
// BIO-01: write dispatch is disabled by default (BIOSTAR_ENV above has no
// BIOSTAR_WRITE_DISPATCH_ENABLED) — tests that specifically exercise the
// add_user write path opt in explicitly with this fixture instead, exactly
// as a real deployment would have to.
const BIOSTAR_ENV_WRITE_ENABLED = { ...BIOSTAR_ENV, BIOSTAR_WRITE_DISPATCH_ENABLED: 'true' };

function fakeBiostarFetch({ onLogin = () => new Response('{}', { status: 200, headers: { 'bs-session-id': 'sess-1' } }), onRequest } = {}) {
    return async (url, options = {}) => {
        if (url.endsWith('/api/login')) return onLogin(url, options);
        return onRequest(url, options);
    };
}

test('Suprema (BioStar2) adapter fails fast on missing required config', () => {
    assert.throws(() => new SupremaDeviceGatewayAdapter({}), /BIOSTAR_BASE_URL/);
    assert.throws(() => new SupremaDeviceGatewayAdapter({ BIOSTAR_BASE_URL: 'https://biostar.test' }), /BIOSTAR_USERNAME/);
});

test('Suprema adapter authenticates via BioStar2 REST and maps a device to heartbeat shape', async () => {
    const deviceResponse = {
        Device: {
            status: '1',
            version: { product_name: 'XP2-MDPB', firmware: '1.5.0' },
            card: { use_em: 'true', use_mifare_felica: 'true', use_csn: 'true' },
        },
    };
    const fetchImpl = fakeBiostarFetch({
        onRequest: async () => new Response(JSON.stringify(deviceResponse), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    });
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV, fetchImpl);

    const heartbeat = await adapter.heartbeat('544452273');
    assert.equal(heartbeat.status, 'online');
    assert.equal(heartbeat.capabilities.product_name, 'XP2-MDPB');
    assert.equal(heartbeat.firmware_version, '1.5.0');
    assert.equal(heartbeat.capabilities.use_em, true);
});

test('Suprema adapter pullEvents filters to the requested device and only events after the checkpoint', async () => {
    const eventsResponse = {
        EventCollection: {
            rows: [
                { id: '46', datetime: '2026-09-18T12:47:52.00Z', device_id: { id: '544452273' }, event_type_id: { code: '20736' } },
                { id: '45', datetime: '2026-09-18T12:45:38.00Z', device_id: { id: '544452273' }, event_type_id: { code: '8192' } },
                { id: '30', datetime: '2026-09-18T10:00:00.00Z', device_id: { id: 'OTHER-DEVICE' }, event_type_id: { code: '8192' } },
            ],
        },
    };
    const fetchImpl = fakeBiostarFetch({
        onRequest: async () => new Response(JSON.stringify(eventsResponse), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    });
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV, fetchImpl);

    const events = await adapter.pullEvents('544452273', { streamEpoch: 0, lastNativeEventId: 45 }, 100);
    assert.equal(events.length, 1);
    assert.equal(events[0].native_event_id, 46);
    assert.equal(events[0].stream_epoch, 0);
    assert.equal(events[0].event_code, 'biostar:20736');
    assert.equal(events[0].ingestion_source, 'biostar-import');
});

test('Suprema adapter re-authenticates once on a 401 and retries the request', async () => {
    let loginCount = 0;
    let requestCount = 0;
    const fetchImpl = fakeBiostarFetch({
        onLogin: async () => { loginCount += 1; return new Response('{}', { status: 200, headers: { 'bs-session-id': `sess-${loginCount}` } }); },
        onRequest: async () => {
            requestCount += 1;
            if (requestCount === 1) return new Response('{}', { status: 401 });
            return new Response(JSON.stringify({ Device: { status: '1' } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        },
    });
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV, fetchImpl);

    const heartbeat = await adapter.heartbeat('544452273');
    assert.equal(heartbeat.status, 'online');
    assert.equal(loginCount, 2);
    assert.equal(requestCount, 2);
});

test('connector tick posts a large event backlog in bounded chunks, not one large request', async () => {
    const manyEvents = Array.from({ length: 45 }, (_, i) => ({ native_event_id: i + 1, stream_epoch: 0 }));
    const adapter = {
        mode: 'fake',
        label: 'fake',
        heartbeat: async () => ({ status: 'online', capabilities: {} }),
        applyCommand: async () => ({}),
        pullEvents: async () => manyEvents,
    };
    const postedChunks = [];
    const client = {
        commands: async () => ({ commands: [], checkpoint: { streamEpoch: 0, lastNativeEventId: 0 }, deviceIdentifier: '544452273' }),
        heartbeat: async () => ({}),
        acknowledge: async () => ({}),
        events: async (deviceId, chunk) => { postedChunks.push(chunk); },
    };

    const connector = createConnector({ client, adapter, deviceIds: ['device-uuid-1'] });
    await connector.tick();

    assert.equal(postedChunks.length, 3); // 20 + 20 + 5
    assert.equal(postedChunks[0].length, 20);
    assert.equal(postedChunks[1].length, 20);
    assert.equal(postedChunks[2].length, 5);
    assert.equal(connector.state.lastError, null);
});

test('Suprema adapter applyCommand("add_user") creates a new BioStar user when none exists', async () => {
    const calls = [];
    const fetchImpl = fakeBiostarFetch({
        onRequest: async (url, options) => {
            calls.push({ url, method: options.method || 'GET' });
            // Real BioStar2 behavior (confirmed live): "not found" is HTTP
            // 400 with a Response.code/message body, never HTTP 404.
            if (url.endsWith('/api/users/100')) {
                return new Response(JSON.stringify({ Response: { code: '201', message: 'User can not be found with id' } }), { status: 400, headers: { 'Content-Type': 'application/json' } });
            }
            if (url.endsWith('/api/users') && options.method === 'POST') {
                const sentBody = JSON.parse(options.body);
                // Real BioStar2 behavior (confirmed live): sending
                // user_group_id.id as a JSON string, not a number, is
                // rejected with "Invalid Parameters" — guard against that
                // regression here, not just in the adapter comment.
                assert.equal(typeof sentBody.User.user_group_id.id, 'number');
                return new Response(JSON.stringify({ User: sentBody.User }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            }
            throw new Error(`unexpected request: ${options.method} ${url}`);
        },
    });
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV_WRITE_ENABLED, fetchImpl);

    const result = await adapter.applyCommand('544452273', {
        type: 'add_user',
        payload: { employee_id: 'uuid-1', employee_internal_code: '100', employee_name: 'Test Employee' },
    });

    assert.equal(result.result, 'succeeded');
    assert.equal(result.deviceEcho.updated, false);
    assert.equal(result.deviceEcho.user_id, '100');
    assert.equal(calls.some((c) => c.method === 'POST' && c.url.endsWith('/api/users')), true);
});

test('Suprema adapter applyCommand("add_user") updates via PUT when the user already exists (idempotent retry)', async () => {
    const fetchImpl = fakeBiostarFetch({
        onRequest: async (url, options) => {
            if (url.endsWith('/api/users/100') && (!options.method || options.method === 'GET')) {
                return new Response(JSON.stringify({ User: { user_id: '100', name: 'Test Employee' } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            }
            if (url.endsWith('/api/users/100') && options.method === 'PUT') {
                return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } });
            }
            throw new Error(`unexpected request: ${options.method} ${url}`);
        },
    });
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV_WRITE_ENABLED, fetchImpl);

    const result = await adapter.applyCommand('544452273', {
        type: 'add_user',
        payload: { employee_id: 'uuid-1', employee_internal_code: '100', employee_name: 'Test Employee' },
    });

    assert.equal(result.result, 'succeeded');
    assert.equal(result.deviceEcho.updated, true);
});

test('Suprema adapter applyCommand("add_user") derives a purely numeric user_id when the internal code is not numeric', async () => {
    let capturedUserId;
    const fetchImpl = fakeBiostarFetch({
        onRequest: async (url, options) => {
            const match = url.match(/\/api\/users\/(\d+)$/);
            if (match) {
                capturedUserId = match[1];
                return new Response(JSON.stringify({ Response: { code: '201', message: 'User can not be found with id' } }), { status: 400, headers: { 'Content-Type': 'application/json' } });
            }
            if (url.endsWith('/api/users') && options.method === 'POST') {
                const sentBody = JSON.parse(options.body);
                assert.match(sentBody.User.user_id, /^9\d{8}$/);
                return new Response(JSON.stringify({ User: sentBody.User }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            }
            throw new Error(`unexpected request: ${options.method} ${url}`);
        },
    });
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV_WRITE_ENABLED, fetchImpl);

    const first = await adapter.applyCommand('544452273', {
        type: 'add_user',
        payload: { employee_id: 'uuid-1', employee_internal_code: 'CLAUDE-TEST-001', employee_name: 'Test Employee' },
    });
    assert.equal(first.result, 'succeeded');
    assert.match(capturedUserId, /^9\d{8}$/);

    // Deterministic: the same non-numeric code always derives the same id.
    const second = await adapter.applyCommand('544452273', {
        type: 'add_user',
        payload: { employee_id: 'uuid-1', employee_internal_code: 'CLAUDE-TEST-001', employee_name: 'Test Employee' },
    });
    assert.equal(second.deviceEcho.user_id, first.deviceEcho.user_id);
});

test('Suprema adapter applyCommand rejects unsupported command types without touching the network', async () => {
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV_WRITE_ENABLED, fakeBiostarFetch({ onRequest: async () => { throw new Error('should not be called'); } }));

    const result = await adapter.applyCommand('544452273', { type: 'revoke_credential', payload: {} });
    assert.equal(result.result, 'failed');
    assert.match(result.error, /Unsupported command type/);
});

test('Suprema adapter applyCommand("add_user") fails without a usable employee identifier', async () => {
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV_WRITE_ENABLED, fakeBiostarFetch({ onRequest: async () => { throw new Error('should not be called'); } }));

    const result = await adapter.applyCommand('544452273', { type: 'add_user', payload: {} });
    assert.equal(result.result, 'failed');
    assert.match(result.error, /missing employee_internal_code\/employee_id/);
});

// BIO-04: TLS trust is scoped per adapter instance (an undici Agent passed
// as `dispatcher`), never the process-wide NODE_TLS_REJECT_UNAUTHORIZED
// mutation this class used before.
test('BIO-04: process-wide NODE_TLS_REJECT_UNAUTHORIZED is never touched, even with BIOSTAR_VERIFY_TLS=false', () => {
    const before = process.env.NODE_TLS_REJECT_UNAUTHORIZED;
    delete process.env.NODE_TLS_REJECT_UNAUTHORIZED;

    new SupremaDeviceGatewayAdapter({ ...BIOSTAR_ENV, BIOSTAR_VERIFY_TLS: 'false' }, fakeBiostarFetch({ onRequest: async () => new Response('{}') }));

    assert.equal(process.env.NODE_TLS_REJECT_UNAUTHORIZED, undefined);
    if (before !== undefined) process.env.NODE_TLS_REJECT_UNAUTHORIZED = before;
});

test('BIO-04: a configured CA cert file is read at construction time; an unreadable path fails fast with a clear error', () => {
    const dir = mkdtempSync(join(tmpdir(), 'biostar-ca-'));
    const certPath = join(dir, 'biostar-ca.pem');
    writeFileSync(certPath, '-----BEGIN CERTIFICATE-----\nFAKE\n-----END CERTIFICATE-----\n');

    // Valid, readable path — construction succeeds.
    assert.doesNotThrow(() => new SupremaDeviceGatewayAdapter({ ...BIOSTAR_ENV, BIOSTAR_CA_CERT_PATH: certPath }));

    // Missing path — fails fast and names the problem, rather than silently
    // falling back to system trust roots or ignoring the operator's config.
    assert.throws(
        () => new SupremaDeviceGatewayAdapter({ ...BIOSTAR_ENV, BIOSTAR_CA_CERT_PATH: join(dir, 'does-not-exist.pem') }),
        /BIOSTAR_CA_CERT_PATH/,
    );
});

test('BIO-04: heartbeat treats a numeric (not string) device.status "1" as online, not a type-technicality offline', async () => {
    const fetchImpl = fakeBiostarFetch({
        onRequest: async () => new Response(JSON.stringify({ Device: { status: 1 } }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    });
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV, fetchImpl);

    const heartbeat = await adapter.heartbeat('544452273');
    assert.equal(heartbeat.status, 'online');
});

test('BIO-04: repeated tick failures back off instead of retrying every poll interval, and a success resets it', async () => {
    let shouldFail = true;
    const adapter = {
        mode: 'fake',
        label: 'fake',
        heartbeat: async () => { if (shouldFail) throw new Error('BioStar unreachable'); return { status: 'online', capabilities: {} }; },
        applyCommand: async () => ({}),
        pullEvents: async () => [],
    };
    const client = {
        commands: async () => ({ commands: [], checkpoint: { streamEpoch: 0, lastNativeEventId: 0 }, deviceIdentifier: 'dev-1' }),
        heartbeat: async () => {},
        acknowledge: async () => {},
        events: async () => {},
    };
    const connector = createConnector({ client, adapter, deviceIds: ['device-uuid-1'], pollIntervalMs: 1000, logger: { error: () => {} } });

    await connector.tick();
    assert.equal(connector.state.consecutiveFailures, 1);
    assert.ok(connector.state.backoffUntil > Date.now());
    const firstBackoffUntil = connector.state.backoffUntil;

    // A tick attempted while still inside the backoff window is skipped
    // entirely — it must not even try, let alone count as a second failure.
    await connector.tick();
    assert.equal(connector.state.consecutiveFailures, 1);
    assert.equal(connector.state.backoffUntil, firstBackoffUntil);

    // Force past the backoff window and fail again — backoff grows.
    connector.state.backoffUntil = Date.now() - 1;
    await connector.tick();
    assert.equal(connector.state.consecutiveFailures, 2);
    assert.ok(connector.state.backoffUntil - Date.now() > firstBackoffUntil - Date.now() - 1000);

    // A success clears both counters.
    connector.state.backoffUntil = Date.now() - 1;
    shouldFail = false;
    await connector.tick();
    assert.equal(connector.state.consecutiveFailures, 0);
    assert.equal(connector.state.backoffUntil, null);
});

test('BIO-04: a request that exceeds BIOSTAR_REQUEST_TIMEOUT_MS is aborted instead of hanging forever', async () => {
    const fetchImpl = (url, options) => new Promise((resolve, reject) => {
        const timer = setTimeout(() => resolve(new Response(JSON.stringify({ Device: { status: '1' } }), { status: 200, headers: { 'Content-Type': 'application/json' } })), 200);
        options?.signal?.addEventListener('abort', () => {
            clearTimeout(timer);
            reject(new Error('The operation was aborted'));
        });
    });
    const adapter = new SupremaDeviceGatewayAdapter({ ...BIOSTAR_ENV, BIOSTAR_REQUEST_TIMEOUT_MS: '20' }, fetchImpl);

    await assert.rejects(() => adapter.heartbeat('544452273'), /aborted/i);
});

// BIO-01: read-only is the default posture for the real-BioStar adapter —
// every write command type must be refused before any HTTP request is made,
// unless BIOSTAR_WRITE_DISPATCH_ENABLED is explicitly 'true'.
test('BIO-01: Suprema adapter refuses add_user without any network call when write dispatch is disabled (default)', async () => {
    const adapter = new SupremaDeviceGatewayAdapter(BIOSTAR_ENV, fakeBiostarFetch({ onRequest: async () => { throw new Error('should not be called'); } }));

    const result = await adapter.applyCommand('544452273', {
        type: 'add_user',
        payload: { employee_id: 'uuid-1', employee_internal_code: '100', employee_name: 'Test Employee' },
    });

    assert.equal(result.result, 'failed');
    assert.match(result.error, /read-only mode/);
});

test('BIO-01: Suprema adapter refuses add_user without any network call when the flag is set to anything other than "true"', async () => {
    const adapter = new SupremaDeviceGatewayAdapter(
        { ...BIOSTAR_ENV, BIOSTAR_WRITE_DISPATCH_ENABLED: '1' },
        fakeBiostarFetch({ onRequest: async () => { throw new Error('should not be called'); } }),
    );

    const result = await adapter.applyCommand('544452273', { type: 'add_user', payload: { employee_internal_code: '100' } });
    assert.equal(result.result, 'failed');
    assert.match(result.error, /read-only mode/);
});
