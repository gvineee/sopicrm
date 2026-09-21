# device-connector

Internal Node.js worker between the Laravel application and access-control readers. The browser never calls this service and no reader/device port is exposed publicly.

## Status

- The versioned Laravel contract is implemented at `/api/v1/device-connector`.
- Every request uses an organization-bound Sanctum machine token, a fresh nonce/timestamp, and an idempotency key for writes.
- The simulator adapter is operational and is always labeled **„სატესტო რეჟიმი“**.
- The Suprema adapter targets BioStar2's own REST API (pivoted from G-SDK gRPC — see `docs/decisions.md` DEC-080) and is **read-only, hardware-verified for reads** against a real XPass 2 (device/user/event endpoints all confirmed). Its write path (`applyCommand` — currently `add_user` only) is implemented and unit-tested (13/13, mocked) but **NOT yet confirmed against real hardware** — see `docs/decisions.md` DEC-082 and its addenda for the two live attempts made so far, the bugs found, and the fix that still needs a live run to confirm. Card enrollment and access-group sync remain unimplemented (see DEC-082).

## Contract

For each configured device the worker:

1. posts heartbeat and capability snapshots;
2. receives the durable epoch/native-event checkpoint while polling pending/retry commands and acknowledges command outcomes;
3. asks the adapter for events from that checkpoint and uploads append-only, overlap-safe batches.

Laravel deduplicates events by `(organization_id, device_id, native_event_id, stream_epoch)`. Payload hashes are diagnostic only. The simulator also rejects stale command versions after a newer version has been applied.

Required machine-token abilities:

- `device-connector:heartbeat.write`
- `device-connector:commands.read`
- `device-connector:commands.write`
- `device-connector:events.write`

Issue a token once, out of band:

```bash
php artisan tokens:issue-machine ORGANIZATION_UUID device-connector-site-1 \
  --ability=device-connector:heartbeat.write \
  --ability=device-connector:commands.read \
  --ability=device-connector:commands.write \
  --ability=device-connector:events.write
```

Store the returned value in the deployment secret store as `DEVICE_CONNECTOR_TOKEN`; never commit it.

## Configuration

| Variable | Purpose | Default |
|---|---|---|
| `DEVICE_CONNECTOR_MODE` | `simulator` or `suprema` | `simulator` |
| `DEVICE_CONNECTOR_LARAVEL_URL` | Internal Laravel contract base URL | `http://app:8000/api/v1/device-connector` |
| `DEVICE_CONNECTOR_TOKEN` | Sanctum machine token | required when devices are configured |
| `DEVICE_CONNECTOR_DEVICE_IDS` | Comma-separated device UUIDs owned by the token's organization | empty |
| `DEVICE_CONNECTOR_POLL_INTERVAL_MS` | Worker interval, minimum 1000 ms | `5000` |
| `DEVICE_CONNECTOR_PORT` | Internal health endpoint port | `4000` |

The health endpoint is `GET /health`. Docker Compose exposes it only to the internal network, not to the host/public internet.

### Suprema mode configuration (`DEVICE_CONNECTOR_MODE=suprema`)

Researched and documented in `docs/decisions.md` DEC-080 (supersedes the earlier gRPC-based DEC-079 plan). These are validated eagerly by `SupremaDeviceGatewayAdapter`'s constructor — a missing required value throws immediately rather than failing silently later. **Never commit real values for these** — set them only in an untracked local `.env`/deployment secret store:

| Variable | Purpose | Default |
|---|---|---|
| `BIOSTAR_BASE_URL` | BioStar2 server base URL, e.g. `https://192.168.x.x`, required | none — required |
| `BIOSTAR_USERNAME` | BioStar2 login id, required | none — required |
| `BIOSTAR_PASSWORD` | BioStar2 password, required | none — required |
| `BIOSTAR_VERIFY_TLS` | Verify the server's TLS certificate | `true` — set to `false` only for a known self-signed LAN deployment, and prefer `BIOSTAR_CA_CERT_PATH` below instead when the server's own certificate is available |
| `BIOSTAR_CA_CERT_PATH` | Path to a PEM file containing the BioStar server's own (typically self-signed) certificate, trusted specifically instead of disabling verification entirely. Read once at startup; an unreadable path fails fast with a clear error rather than silently falling back to system trust roots. | unset — no extra CA trusted |
| `BIOSTAR_REQUEST_TIMEOUT_MS` | Per-request timeout (login, device/user/event calls) — a hung BioStar server aborts the request instead of blocking a tick forever | `10000` |
| `BIOSTAR_DEFAULT_USER_GROUP_ID` | BioStar `user_group_id.id` to assign new users to (sent as a JSON number, not a string) | `1` (BioStar's built-in "All Users" group) |
| `BIOSTAR_DEFAULT_USER_GROUP_NAME` | Matching `user_group_id.name` | `All Users` |
| `BIOSTAR_WRITE_DISPATCH_ENABLED` | **BIO-01 read-only gate.** Must be the literal string `true` to allow `applyCommand()` to send anything to a real BioStar server. Any other value (including unset) makes `applyCommand()` refuse every command type immediately, with no HTTP request made. Mirrored server-side by Laravel's `devices.biostar_write_dispatch_enabled` config, which independently withholds write commands from this connector's `/commands` poll in the first place — both boundaries must be flipped on together, deliberately, once a write integration is pilot-confirmed. | `false` (read-only) |

**BIO-04:** TLS trust and timeouts are scoped to this adapter's own requests via a dedicated `undici` `Agent` passed as `dispatcher` on every fetch call. This class no longer sets `process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0'` — that was a process-wide mutation that would have silently disabled certificate verification for every other outbound HTTPS call this process makes (including to Laravel itself), not just calls to BioStar.

`heartbeat`/`pullEvents` (device capability reads and event log polling) are implemented and live-verified against a real XPass 2, and are unaffected by the read-only gate above — read-only mode means BioStar itself is never written to, not that the connector stops polling it. `applyCommand('add_user')` (create/update a BioStar user from an issued credential) is implemented and unit-tested but has been rejected twice in real live testing with `"Invalid Parameters"` — first by a `user_group_id.id` string-vs-number bug (fixed), then apparently by BioStar's own default numeric-only `user_id` restriction (a fix is implemented — non-numeric internal codes are hashed to a numeric id — but not yet live-confirmed). It is also, as of BIO-01, gated behind `BIOSTAR_WRITE_DISPATCH_ENABLED=true` on both ends (see table above) — first-stage integration is read-only by design: BioStar owns devices/cards/access, the CRM only copies user metadata and events. Card enrollment and access-group sync remain unimplemented regardless of this flag.

### Testing the live write path

The write path cannot be exercised safely by an automated agent against production hardware, so this is a manual step for whoever operates the BioStar server. It also requires deliberately opting out of the BIO-01 read-only default:

1. Make sure `services/device-connector/.env` (or your shell env) has `DEVICE_CONNECTOR_MODE=suprema`, `BIOSTAR_WRITE_DISPATCH_ENABLED=true`, and the other `BIOSTAR_*` variables above pointing at the real server. Set `devices.biostar_write_dispatch_enabled` (env `BIOSTAR_WRITE_DISPATCH_ENABLED=true` in Laravel's own `.env`) the same way, or the command will never even reach the connector's `/commands` poll.
2. Make sure a `DeviceSyncCommand` of type `add_user` is queued (`status` `pending` or `retry`) for a device this connector polls — e.g. the one staged from `IssueCredentialAction` for the disposable test employee (`internal_code = CLAUDE-TEST-001`).
3. Run one poll cycle: `cd services/device-connector && npm start` (it polls every `DEVICE_CONNECTOR_POLL_INTERVAL_MS`, default 5s — you can `Ctrl+C` after the first tick).
4. Check the result in Laravel: the `DeviceSyncCommand` row should flip to `succeeded` (and a real user should now exist on the BioStar server, `GET /api/users/{id}`) or `retry`/`failed` with a new `last_error`.
5. Report back whichever happened — if it still says `"Invalid Parameters"`, the next thing to compare byte-for-byte against a known-good request is `start_datetime`/`expiry_datetime` formatting (see DEC-082's addendum).
6. Turn `BIOSTAR_WRITE_DISPATCH_ENABLED` back off (or unset it) on both ends afterward — it should stay off by default outside of a deliberate, supervised test like this one.

## Run and test

```bash
cd services/device-connector
npm test
npm start
```

Or run it with the local stack:

```bash
docker compose -f infra/docker-compose.yml up device-connector
```

Real Suprema activation remains blocked until the documented pilot covers employee → card assignment → reader confirmation → event ingestion → revocation confirmation, including outage/replay behavior and rollback.
