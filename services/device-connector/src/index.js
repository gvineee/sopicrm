import 'dotenv/config';
import http from 'node:http';
import { pathToFileURL } from 'node:url';

import { SimulatorAdapter } from './adapters/simulator.js';
import { SupremaDeviceGatewayAdapter } from './adapters/suprema-device-gateway.js';
import { LaravelConnectorClient } from './laravel-client.js';

const PORT = Number(process.env.DEVICE_CONNECTOR_PORT || 4000);
const MODE = process.env.DEVICE_CONNECTOR_MODE || 'simulator';
const DEVICE_IDS = (process.env.DEVICE_CONNECTOR_DEVICE_IDS || '').split(',').map((value) => value.trim()).filter(Boolean);
const POLL_INTERVAL_MS = Math.max(Number(process.env.DEVICE_CONNECTOR_POLL_INTERVAL_MS || 5000), 1000);

export function buildAdapter(mode) {
    if (mode === 'simulator') return new SimulatorAdapter();
    if (mode === 'suprema') return new SupremaDeviceGatewayAdapter();
    throw new Error(`Unsupported DEVICE_CONNECTOR_MODE: ${mode}`);
}

// BIO-04: repeated tick failures (e.g. BioStar unreachable) back off instead
// of hammering the server every POLL_INTERVAL_MS forever — doubles from the
// base poll interval up to this ceiling.
const MAX_BACKOFF_MS = 5 * 60 * 1000;

export function createConnector({ client, adapter, deviceIds, logger = console, pollIntervalMs = 5000 }) {
    let running = false;
    const state = {
        mode: adapter.mode,
        adapterLabel: adapter.label,
        configuredDevices: deviceIds.length,
        lastTickAt: null,
        lastSuccessfulTickAt: null,
        lastError: null,
        consecutiveFailures: 0,
        backoffUntil: null,
    };

    async function tick() {
        if (running) return;
        if (state.backoffUntil !== null && Date.now() < state.backoffUntil) return;
        running = true;
        try {
            for (const deviceId of deviceIds) {
                // `deviceId` is this API's Laravel device UUID; `adapterDeviceId`
                // is whatever identifier the adapter itself needs (e.g. a
                // BioStar2 numeric device id) — the two are unrelated
                // identifier spaces for real hardware. Simulator devices have
                // no `device_identifier` set, so this falls back to the
                // Laravel UUID, preserving existing simulator behavior.
                const { commands, checkpoint, deviceIdentifier } = await client.commands(deviceId);
                const adapterDeviceId = deviceIdentifier || deviceId;

                await client.heartbeat(deviceId, await adapter.heartbeat(adapterDeviceId));
                for (const command of commands) {
                    await client.acknowledge(deviceId, command.id, await adapter.applyCommand(adapterDeviceId, command));
                }
                const events = await adapter.pullEvents(adapterDeviceId, checkpoint, 500);
                // Posted in bounded chunks rather than one potentially-large
                // array: a real device can return a large backlog after an
                // outage, and some PHP deployments buffer large POST bodies
                // to a temp file that isn't always writable/configured —
                // smaller, more frequent commits are both more portable and
                // recover better from a partial failure. Each chunk gets its
                // own idempotency key (LaravelConnectorClient derives it from
                // that chunk's own first/last event), so this is safe to
                // retry per-chunk without re-deriving anything here.
                for (let i = 0; i < events.length; i += 20) {
                    const chunk = events.slice(i, i + 20);
                    if (chunk.length > 0) await client.events(deviceId, chunk);
                }
            }
            state.lastSuccessfulTickAt = new Date().toISOString();
            state.lastError = null;
            state.consecutiveFailures = 0;
            state.backoffUntil = null;
        } catch (error) {
            state.lastError = error instanceof Error ? error.message : String(error);
            state.consecutiveFailures += 1;
            const backoffMs = Math.min(pollIntervalMs * 2 ** state.consecutiveFailures, MAX_BACKOFF_MS);
            state.backoffUntil = Date.now() + backoffMs;
            logger.error(`[device-connector] tick failed (consecutive failures: ${state.consecutiveFailures}, backing off ${backoffMs}ms)`, error);
        } finally {
            state.lastTickAt = new Date().toISOString();
            running = false;
        }
    }
    return { state, tick };
}

export function createHealthServer(state) {
    return http.createServer((req, res) => {
        if (req.method === 'GET' && req.url === '/health') {
            res.writeHead(state.lastError ? 503 : 200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ status: state.lastError ? 'degraded' : 'ok', configured: state.configuredDevices > 0, realHardwareValidated: false, ...state }));
            return;
        }
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: { code: 'not_found', message: 'Not found.' } }));
    });
}

async function main() {
    const adapter = buildAdapter(MODE);
    const client = new LaravelConnectorClient({
        baseUrl: process.env.DEVICE_CONNECTOR_LARAVEL_URL || 'http://app:8000/api/v1/device-connector',
        token: process.env.DEVICE_CONNECTOR_TOKEN || '',
        connectorVersion: process.env.npm_package_version || '1.0.0',
    });
    const connector = createConnector({ client, adapter, deviceIds: DEVICE_IDS, pollIntervalMs: POLL_INTERVAL_MS });
    const server = createHealthServer(connector.state);
    const timer = setInterval(connector.tick, POLL_INTERVAL_MS);
    server.listen(PORT, '0.0.0.0', () => {
        console.log(`[device-connector] ${adapter.label} listening internally on :${PORT}`);
        console.log(`[device-connector] configured devices: ${DEVICE_IDS.length}; real hardware validated: NO`);
    });
    await connector.tick();
    const shutdown = () => { clearInterval(timer); server.close(() => process.exit(0)); };
    process.on('SIGTERM', shutdown);
    process.on('SIGINT', shutdown);
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
    main().catch((error) => { console.error('[device-connector] fatal startup error', error); process.exit(1); });
}
