// PLACEHOLDER — Foundation-phase scaffold only.
//
// This is NOT a real Suprema G-SDK / Device Gateway integration. It exists so
// `infra/docker-compose.yml` has a real, runnable container in the shape the
// Devices module will fill in, and so nobody can mistake "the container
// starts" for "the device integration works" (per the hard constraint: never
// mark real hardware integration as validated when only a simulator/placeholder
// was exercised).
//
// What the real implementation must do (docs/architecture.md §6):
//   - Speak Suprema G-SDK / Device Gateway to devices on the protected LAN/VPN.
//     Never expose device ports to the public internet; the browser never
//     talks to this process directly.
//   - Authenticate to the main Laravel app as a distinct machine identity
//     (Sanctum token scoped to this purpose), never a shared/generic API key,
//     with replay protection (nonce/timestamp + idempotency key).
//   - Implement DeviceAdapterInterface with two adapters side by side: a
//     Simulator (always visibly labeled "სატესტო რეჟიმი" / "TEST MODE" in the
//     UI wherever its data is shown) and a real Suprema adapter.
//   - Ingest RawAccessEvent append-only, deduplicated on
//     (device_id, native_event_id, stream_epoch) — never on payload hash alone.
//
// Exact language/runtime is not locked in by this placeholder — Node.js was
// chosen only as a convenient placeholder default (docs/architecture.md §6
// allows "a small PHP or Node service unless the Suprema SDK strongly favors
// one"). The Devices module agent may change this when it implements the
// real adapter, and must update this file's header and README.md accordingly.

import http from 'node:http';

const PORT = process.env.DEVICE_CONNECTOR_PORT || 4000;
const MODE = process.env.DEVICE_CONNECTOR_MODE || 'simulator';

const server = http.createServer((req, res) => {
    if (req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'ok',
            mode: MODE,
            note: 'PLACEHOLDER service — no real device integration implemented yet.',
        }));
        return;
    }

    res.writeHead(501, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
        error: {
            code: 'not_implemented',
            message: 'device-connector is a Foundation-phase placeholder. The Devices module has not implemented this endpoint yet.',
        },
    }));
});

server.listen(PORT, () => {
    console.log(`[device-connector] PLACEHOLDER service listening on :${PORT} (mode=${MODE})`);
    console.log('[device-connector] No real Suprema G-SDK integration exists yet — see README.md.');
});

process.on('SIGTERM', () => server.close(() => process.exit(0)));
process.on('SIGINT', () => server.close(() => process.exit(0)));
