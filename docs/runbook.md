# ODA CRM — Runbook

Local setup, tooling, and operational procedures for ODA CRM. Sections below are filled in progressively as later phases build on the Foundation scaffold. See `docs/decisions.md` for the reasoning behind each choice.

---

## Required local tooling

| Tool | Target version | Status in this environment (checked 2026-09-16, Foundation scaffold pass) | Notes |
|---|---|---|---|
| PHP | ^8.3 (8.4 recommended) | **PRESENT** — 8.4.25 (via Laravel Herd; not on the default Git-Bash `PATH`, but available in PowerShell/cmd) | Required for Laravel 13. |
| Composer | 2.x (latest stable) | **PRESENT** — 2.10.2 | |
| Node.js | 20.x LTS or newer | **PRESENT** — v24.20.0 | Satisfies Vite/Vue tooling requirements. |
| npm | 10.x or newer | **PRESENT** — v11.19.0 | |
| PostgreSQL | 16.x server + `psql` client | Provided via `infra/docker-compose.yml` (`postgres` service, image `postgres:16-alpine`) — no local native install required or assumed. A native install is fine too if you prefer running the app outside Docker; point `.env`'s `DB_*` vars at it. | Must support the `btree_gist` extension (used for rate/session exclusion constraints, `docs/data-model.md`) and Row-Level Security (standard in Postgres, no extension needed) — both added when RLS policies are implemented in a later phase, not part of this Foundation scaffold. |
| Redis | 7.x | Provided via `infra/docker-compose.yml` (`redis` service, image `redis:7-alpine`). | Used for sessions/cache/queue in every environment, including local dev — see `docs/decisions.md` DEC-025. |
| Docker + Docker Compose | Current stable | **Not verified in this environment** (`docker`/`docker compose` not found on this machine's `PATH` during the Foundation pass) — the compose stack has been authored and reviewed but not build/run-tested here. A human operator with Docker installed must run the first `docker compose up` and confirm the stack comes up cleanly. | Needed for `infra/docker-compose.yml`. |

**Note on this environment's shells (Windows):** `php`, `composer` (via Herd) are only on PowerShell/cmd's `PATH`, not Git-Bash's. Run PHP/Composer/artisan commands from PowerShell (or prefix Bash commands with the full Herd path) until/unless Git-Bash's `PATH` is updated.

### Suggested install approach for the human operator, if Docker is not yet installed (informational only — no agent runs these automatically)

- **Docker Desktop (Windows)**: needed for the full local stack (Postgres, Redis, MinIO, Mailpit, device-connector) described in `docs/architecture.md` §1 and `infra/docker-compose.yml`. Everything else (PHP, Composer, Node, npm) is already present in this environment as of the Foundation scaffold pass above.
- If you'd rather run Postgres/Redis natively instead of via Docker, that's fine too — just point `.env`'s `DB_*`/`REDIS_*` vars at your local instances instead of running those two services from the compose file.

---

## Local setup (Foundation scaffold)

From the project root, with Docker Desktop running:

```powershell
# 1. PHP dependencies
composer install

# 2. Frontend dependencies
npm install

# 3. Environment file (already present in this repo checkout for convenience,
#    but this is the step for a fresh clone)
Copy-Item .env.example .env

# 4. Start Postgres, Redis, Mailpit, MinIO, device-connector (placeholder)
docker compose -f infra/docker-compose.yml up -d

# 5. App key (already generated in the committed .env for this scaffold; a
#    fresh clone needs this)
php artisan key:generate

# 6. Run migrations (currently just the starter kit's users/cache/jobs/passkeys
#    tables — the full P0/P1 data model is added in a later phase)
php artisan migrate

# 7. Frontend dev server (Vite) — runs on the host, not inside Docker, per
#    docs/decisions.md (avoids HMR/file-watcher friction in a container on
#    Windows when Node is already installed locally)
npm run dev
```

Then, in another terminal, run the PHP app itself — either `php artisan serve`
on the host, or rely on the `app` service already started by `docker compose up`
in step 4 (it runs `php artisan serve --host=0.0.0.0 --port=8000` inside the
container, bound to `localhost:8000` on the host via the port mapping). Don't
run both against the same port at once.

Useful local commands:

```powershell
php artisan test              # Pest suite (sqlite in-memory, per phpunit.xml)
vendor\bin\pint.bat           # PHP lint (add --test to check without fixing)
composer run types:check      # PHPStan / Larastan static analysis
npm run types:check           # vue-tsc frontend typecheck
npm run build                 # Frontend production build
docker compose -f infra/docker-compose.yml logs -f   # tail all container logs
docker compose -f infra/docker-compose.yml down      # stop the stack
```

**Windows note:** if `vendor/bin/pint` or `phpstan` reports
`Allowed memory size ... exhausted`, the local PHP CLI's `memory_limit` (often
128M by default in some distributions) is too low for static analysis on this
codebase — either raise `memory_limit` in `php.ini` or pass
`php -d memory_limit=1G vendor\phpstan\phpstan\phpstan.phar analyse`. CI is
unaffected (`shivammathur/setup-php` sets `memory_limit=-1`).

---

## Environments

- **dev**: local machine, Docker Compose stack (`infra/docker-compose.yml`), no seed data beyond RBAC roles/permissions and reference lookups — spec section 21 explicitly forbids production seed data, and dev should mirror that discipline as closely as practical while still being usable for manual testing (factories/seeders clearly marked dev-only).
- **staging**: mirrors production configuration; used for the section 21 performance targets (p95 ≤ 800ms list/read, mobile LCP ≤ 2.5s, event-to-UI lag p95 ≤ 10s) and for the section 17 mandatory real-device PWA install acceptance tests. Hosting provider not yet chosen — see `docs/decisions.md` § Open business-policy questions #6.
- **production**: no seed data; real backups (encrypted DB backup + PITR where the chosen host supports it, object versioning/backup for attachments, target RPO ≤ 15 min / RTO ≤ 4h to be confirmed once hosting is chosen); restore drills documented once infra exists.

---

## CI pipeline

Implemented in `.github/workflows/ci.yml` (GitHub Actions), per spec section 21 ("CI: lint, typecheck, migrations, tests, build"). Runs on every push to `main` and every pull request:

1. `composer install`.
2. PHP lint (`vendor/bin/pint --test`) and static analysis (`composer run types:check`, i.e. PHPStan/Larastan).
3. `php artisan migrate --force` against a real ephemeral Postgres 16 service container (catches Postgres-specific issues sqlite would hide — see the comment in the workflow for why Pest itself still runs against sqlite).
4. Pest test suite (`php artisan test`).
5. `npm ci`, frontend typecheck (`npm run types:check`, i.e. `vue-tsc`), and production build (`npm run build`).

Not yet in CI (future phases, per `docs/decisions.md`):
- RLS-real-role integration tests (added once RLS policies and the `oda_app` restricted DB role exist — `btree_gist`/RLS aren't part of the current schema, which is still just the starter kit's default users/cache/jobs/passkeys tables).
- Vitest frontend unit tests (no component tests exist yet to run).
- Playwright E2E (added when there are real pages/flows to test end-to-end).

---

## Backups & restore (to be filled in once hosting is chosen)

Placeholder — do not fabricate specifics ahead of an actual hosting decision (`docs/decisions.md` § Open business-policy questions #6). Once chosen, this section documents: backup schedule, encryption method, PITR mechanism, object-storage versioning/backup for `attachments`, retention period, and the periodic restore-drill procedure and its last-run date/result, per spec section 21's explicit requirement that migrations have a backup + rollback/forward-fix plan.

---

## Health / monitoring (to be filled in by Foundation + Integration)

Placeholder for: `/health` and `/ready` endpoint behavior, worker graceful-shutdown behavior, and the operational dashboards for device heartbeat, event ingestion lag, command retry counts, dead-letter queue depth, DB health, upload failures, and backup status — per spec section 21's explicit list. UI must distinguish "no data yet" from "confirmed zero," per spec's explicit requirement.

---

## Device-connector operations (to be filled in by the Devices module agent)

`services/device-connector` currently runs as a **placeholder** (see its own `README.md`): a trivial Node process with a `/health` endpoint, started by `docker compose -f infra/docker-compose.yml up device-connector`, port `4000`. No real Suprema G-SDK integration, Sanctum machine token, or Simulator/real-adapter switch exists yet — none of that may be claimed as working until the Devices module agent implements it.

Placeholder for the real operations doc once built: how to start/stop the service, how its Sanctum machine token is provisioned/rotated, how to switch it between Simulator and real-Suprema-adapter mode, and the documented real-hardware pilot test plan referenced in spec section 6/22 (P0 acceptance: employee → card assignment → reader confirmation → event received → revocation confirmed, including outage/replay behavior).
