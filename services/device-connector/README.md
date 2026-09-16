# device-connector (placeholder)

**Status: Foundation-phase placeholder only.** There is no real Suprema G-SDK
or Device Gateway integration here yet. `src/index.js` runs a trivial HTTP
server with a `/health` endpoint so `infra/docker-compose.yml` has something
real to build and run, in the shape the Devices module will implement. Do not
treat this container running as evidence that any real device integration
works — per the project's hard constraints, real-hardware validation can only
be claimed after it is actually exercised against real Suprema hardware, not
a simulator or this placeholder.

## What this service is for (docs/architecture.md §6, ODA_CRM_Claude_Code_Spec.md §6)

A separate OS process, isolated from the main Laravel app, that speaks the
Suprema G-SDK / Device Gateway protocol to biometric/card readers on the
protected site LAN/VPN. It:

- Never exposes device ports to the public internet.
- Is never talked to directly by the browser — all UI flows go through the
  Laravel app, which talks to this service over a versioned, authenticated
  contract.
- Authenticates to Laravel (and is authenticated by Laravel) as a **distinct
  machine identity** (a Sanctum personal-access token scoped to this purpose
  — see docs/decisions.md DEC-009), never a shared/generic API key, and every
  request is protected against replay (nonce/timestamp + idempotency key).
- Implements `DeviceAdapterInterface` (defined in `app/Domain/Devices` in the
  main Laravel app) with two implementations side by side:
  - **Simulator** — always visibly labeled "სატესტო რეჟიმი" / "TEST MODE" in
    the UI wherever its data is shown.
  - **Real Suprema adapter** — the actual G-SDK/Device Gateway integration.
- Ingests `RawAccessEvent`s append-only, deduplicated on
  `(device_id, native_event_id, stream_epoch)` — never on payload hash alone.

The exact transport (an internal `/api/v1/device-connector/*` Sanctum-guarded
route group vs. a Redis queue contract, or both) is an Integration/Devices
module decision to be recorded in `docs/decisions.md` when made — see
docs/architecture.md §6.

## Current placeholder implementation

- Runtime: Node.js (chosen only as a convenient default — see the header
  comment in `src/index.js`; the Devices module agent may change this
  entirely once real Suprema SDK requirements are known, and must update this
  README when it does).
- `GET /health` → `{ status: "ok", mode: "simulator" }`.
- Every other route → HTTP 501 with an explanatory error body.
- No authentication, no queue contract, no real device protocol — none of
  that exists yet.

## Running locally

Via Docker Compose (recommended — see `infra/docker-compose.yml`, service
`device-connector`):

```
docker compose -f infra/docker-compose.yml up device-connector
```

Standalone:

```
cd services/device-connector
npm install   # no real dependencies yet, but keeps the workflow consistent
npm start
```

## What the Devices module agent must do here

1. Decide and document the final connector-Laravel contract shape in
   `docs/decisions.md` (per docs/architecture.md §6).
2. Implement `DeviceAdapterInterface` in `app/Domain/Devices` (Laravel side)
   and the matching Simulator + real Suprema adapter here.
3. Replace this placeholder server with the real service, keeping this
   README's structure but replacing every "placeholder" statement with the
   real, load-bearing description — and updating `infra/docker-compose.yml`'s
   `device-connector` service definition (env vars, ports, health check) to
   match.
4. Add this service's own test suite under a `tests/` directory here.
5. Document the real-hardware pilot test plan (spec §6/§22 P0 acceptance:
   employee → card assignment → reader confirmation → event received →
   revocation confirmed, including outage/replay behavior) in
   `docs/runbook.md` § Device-connector operations.
