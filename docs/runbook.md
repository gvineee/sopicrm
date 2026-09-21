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

# 4. Start Postgres, Redis, Mailpit, MinIO, and the device connector
docker compose -f infra/docker-compose.yml up -d

# 5. App key (already generated in the committed .env for this scaffold; a
#    fresh clone needs this)
php artisan key:generate

# 6. Run migrations — the full P0+P1 data model (docs/data-model.md) is now
#    part of the initial migration set (Access/Employees/Devices/Attendance/
#    Payroll/Assets/Projects&Tasks/DailyJournal + cross-cutting tables),
#    verified against a real local Postgres 17 instance during the P0+P1
#    schema pass (2026-09-16, docs/decisions.md). No Postgres/Docker
#    available here? See "Required local tooling" below — a native local
#    Postgres install (not Docker) is what this environment actually used.
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

**Windows note (`bootstrap/cache` not writable):** if `composer install`/`composer require`
fails on `php artisan package:discover` with "The .../bootstrap/cache directory
must be present and writable" even though the directory clearly exists, check
whether it (or `bootstrap/`) has the NTFS `ReadOnly` attribute set (`Get-Item
bootstrap\cache -Force | Select Attributes` in PowerShell) — PHP's
`is_writable()` respects this legacy Windows flag even though the OS itself
mostly ignores it for directories. Fix: `attrib -R bootstrap; attrib -R
bootstrap\cache`. See DEC-045.

### Auth/RBAC/Tenancy: local Postgres + Redis without Docker (this environment, 2026-09-16)

Docker Desktop is still not installed here (see DEC-024, DEC-043), but this
machine already has a native **PostgreSQL 17** server and **Redis** (via
Scoop) installed — used directly instead:

```powershell
# Redis must be running before `php artisan migrate` (spatie/laravel-permission's
# migration clears its permission cache through the configured CACHE_STORE=redis)
# or before `php artisan serve` (sessions/cache/queue all use Redis per DEC-025).
redis-server        # in its own terminal/background process; redis-cli ping -> PONG

# One-time: create the oda_app role + the two databases DEC-026/DEC-043 expect,
# as the local postgres superuser (adjust host/port/superuser password as needed).
$env:PGPASSWORD = "postgres"
$psql = "C:\Program Files\PostgreSQL\17\bin\psql.exe"
& $psql -U postgres -h 127.0.0.1 -c "CREATE ROLE oda_app LOGIN PASSWORD 'secret';"
& $psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE oda_crm OWNER oda_app;"       # dev
& $psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE oda_crm_test OWNER oda_app;"  # RLS test suite / CI

php artisan migrate:fresh --seed   # RBAC roles/permissions + a demo org+owner user
                                    # (test@example.com / password, see DatabaseSeeder)
```

`tests/Feature/Auth/TenantIsolationRlsTest.php` connects to `oda_crm_test`
directly via `config/database.php`'s `pgsql_rls_test` connection and runs its
own `migrate:fresh` against it — no extra setup beyond the database existing
and `oda_app` being a real (non-superuser) role.

---

## Environments

- **dev**: local machine, Docker Compose stack (`infra/docker-compose.yml`) or a native Postgres/Redis install (see above) — no seed data beyond RBAC roles/permissions (always seeded, every environment — reference data, not "production seed data") plus, outside `APP_ENV=production` only, one demo organization + owner user for manual testing (`database/seeders/DatabaseSeeder.php`, guarded by an explicit `app()->environment('production')` check). Spec section 21 explicitly forbids production seed data; this guard is how that's actually enforced, not just a convention.
- **staging**: mirrors production configuration; used for the section 21 performance targets (p95 ≤ 800ms list/read, mobile LCP ≤ 2.5s, event-to-UI lag p95 ≤ 10s) and for the section 17 mandatory real-device PWA install acceptance tests. Hosting provider not yet chosen — see `docs/decisions.md` § Open business-policy questions #6.
- **production**: no seed data; real backups (encrypted DB backup + PITR where the chosen host supports it, object versioning/backup for attachments, target RPO ≤ 15 min / RTO ≤ 4h to be confirmed once hosting is chosen); restore drills documented once infra exists.

---

## CI pipeline

Implemented in `.github/workflows/ci.yml` (GitHub Actions), per spec section 21 ("CI: lint, typecheck, migrations, tests, build"). Runs on every push to `main` and every pull request:

1. `composer install`.
2. Strip the `SUPERUSER` attribute from the ephemeral Postgres service container's `oda_app` role (the official image grants it by default, which would make RLS a silent no-op — see DEC-043).
3. PHP lint (`vendor/bin/pint --test`) and static analysis (`composer run types:check`, i.e. PHPStan/Larastan).
4. `php artisan migrate --force` against a real ephemeral Postgres 16 service container (catches Postgres-specific issues sqlite would hide — see the comment in the workflow for why Pest itself still runs against sqlite).
5. Pest test suite (`php artisan test`) — the bulk runs against sqlite per `phpunit.xml`, except `tests/Feature/Auth/TenantIsolationRlsTest.php`, which opens its own connection to the same real Postgres service container as the restricted `oda_app` role (DEC-043) and proves cross-tenant RLS isolation for real, not just via the Eloquent-scope layer.
6. `npm ci`, frontend typecheck (`npm run types:check`, i.e. `vue-tsc`), and production build (`npm run build`).

Not yet in CI (future phases, per `docs/decisions.md`):
- Vitest frontend unit tests (no component tests exist yet to run).
- Playwright E2E (added when there are real pages/flows to test end-to-end).

---

## Backups & restore (to be filled in once hosting is chosen)

Placeholder — do not fabricate specifics ahead of an actual hosting decision (`docs/decisions.md` § Open business-policy questions #6). Once chosen, this section documents: backup schedule, encryption method, PITR mechanism, object-storage versioning/backup for `attachments`, retention period, and the periodic restore-drill procedure and its last-run date/result, per spec section 21's explicit requirement that migrations have a backup + rollback/forward-fix plan.

---

## Health / monitoring (to be filled in by Foundation + Integration)

Placeholder for: `/health` and `/ready` endpoint behavior, worker graceful-shutdown behavior, and the operational dashboards for device heartbeat, event ingestion lag, command retry counts, dead-letter queue depth, DB health, upload failures, and backup status — per spec section 21's explicit list. UI must distinguish "no data yet" from "confirmed zero," per spec's explicit requirement.

---

## Design system / PWA shell (design-system pass, 2026-09-16)

### Where things live
- Tokens: `resources/css/app.css` (`:root`/`.dark` — see `docs/decisions.md` DEC-046 for the palette and how contrast was verified).
- Shared layout/shell: `resources/js/layouts/app/AppSidebarLayout.vue` (desktop sidebar + mobile bottom-nav/top-bar in one responsive shell), `resources/js/components/mobile/*`, `resources/js/lib/mobileNav.ts` (reserved bottom-nav slots — DEC-054).
- Standard states: `resources/js/components/states/*.vue` (Loading/Empty/Error/PermissionDenied/Offline/Conflict).
- Data primitives: `resources/js/components/data/*.vue` (DataTable, FilterBar, SavedFilters, TablePagination, DetailDrawer, KanbanBoard) + `resources/js/composables/useServerTable.ts`, `useSavedFilters.ts`.
- PWA: `public/manifest.webmanifest`, `public/sw.js`, `public/offline.html`, `public/icons/*`, `resources/js/lib/pwa.ts` (SW registration/update flow), `resources/js/composables/usePwaInstall.ts`, `resources/js/components/pwa/*.vue`.
- Offline queue primitive: `resources/js/lib/offlineQueue.ts` (IndexedDB; see DEC-053).
- Two local/testing-only QA routes exist purely for responsive-layout screenshotting without a login: `GET design-system/my-day` and `GET design-system/dashboard` (see DEC-050) — 404 outside `APP_ENV=local|testing`.

### Testing PWA installability locally
The manifest/service-worker require a real HTTPS-or-localhost origin and won't do much over `php artisan serve`'s plain HTTP on a non-`localhost` hostname — use `http://127.0.0.1:8000` or `http://localhost:8000` (both count as a "potentially trustworthy origin" for service workers without HTTPS).

1. `npm run build` (or `npm run dev` — the SW/manifest links are static `<link>`/`<meta>` tags in `resources/views/app.blade.php`, unaffected by dev vs. build).
2. `php artisan serve` (or the Docker `app` service).
3. Open `http://127.0.0.1:8000/dashboard` (log in first — a real page is needed for `beforeinstallprompt`'s engagement heuristics; the `design-system/*` QA routes work too and skip login, but only in `local`/`testing`).
4. **Desktop Chrome/Edge**: DevTools → Application tab → *Manifest* (checks the manifest parses, shows icons or a maskable-icon warning) and *Service Workers* (confirms `sw.js` registered, shows status). The install icon in the address bar (⊕) appearing is the real `beforeinstallprompt` signal — the in-app "დააყენე ODA" button (`InstallOdaButton.vue`) only renders once that same browser event has actually fired, per spec 17's feature-detection requirement; it will not appear on a fresh, uninstalled page load until the browser decides the engagement heuristic is satisfied (a page reload or two, and some time on-page, is often needed).
5. **Offline fallback**: DevTools → Application → Service Workers → check "Offline", then reload — should show `public/offline.html`, not a browser error page. Uncheck "Offline" to restore.
6. **Update flow**: after registering once, change something trivial in `public/sw.js` (bump `CACHE_VERSION`), rebuild, reload the tab twice (SW updates are checked on navigation) — the amber "ახალი ვერსია მზადაა" banner (`UpdateAvailableBanner.vue`) should appear; clicking "განახლება" should activate the new worker and reload once.
7. **Android Chrome / iPhone Safari real-device install** (spec 17's mandatory acceptance test): this cannot be done from this dev machine alone — either port-forward the local server to a phone on the same network (`php artisan serve --host=0.0.0.0`, then `http://<your-LAN-IP>:8000` on the phone) or deploy to a real HTTPS staging host. On Android Chrome, confirm the native install prompt/banner and the in-app "დააყენე ODA" button both work and that the button hides once standalone. On iPhone/iPad Safari, confirm the "iPhone/iPad-ზე დაყენება" instructional guide (`IosInstallGuide.vue`) shows (no fake install button — Safari has no `beforeinstallprompt`), and manually follow Share → Add to Home Screen → confirm standalone launch has no browser chrome. **Record OS/browser versions in the test report — a desktop/emulator check alone does not satisfy this acceptance test** (spec 17: "Simulator/browser emulation მარტო არ ითვლება ორივე პლატფორმის ინსტალაციის დამოწმებად"). This was NOT performed as part of this pass (no physical devices available in this environment) — flagged as pending manual QA, not claimed as done.
8. **Offline queue**: open DevTools → Application → IndexedDB → `oda-crm-offline` to inspect `queue_items`/`blobs` once a module starts calling `enqueue()` from `resources/js/lib/offlineQueue.ts`; nothing writes to it yet in this pass (no real draft-producing screen exists), so an empty database at this stage is expected, not a bug.

---

## Device-connector operations

`services/device-connector` is an internal Node worker. Docker Compose does not publish its health port to the host; the browser never calls it. The working simulator is always labeled **„სატესტო რეჟიმი“**. `DEVICE_CONNECTOR_MODE=suprema` deliberately refuses to start until a physical-reader pilot and real Device Gateway configuration exist; no simulator run counts as real-hardware validation.

Provision one organization-bound token with only the four connector abilities:

```bash
php artisan tokens:issue-machine ORGANIZATION_UUID device-connector-site-1 \
  --ability=device-connector:heartbeat.write \
  --ability=device-connector:commands.read \
  --ability=device-connector:commands.write \
  --ability=device-connector:events.write
```

Store the returned token outside Git as `DEVICE_CONNECTOR_TOKEN`. Set `DEVICE_CONNECTOR_DEVICE_IDS` to the comma-separated UUIDs assigned to that site/organization, then start with `docker compose -f infra/docker-compose.yml up -d device-connector`. Rotate by issuing a new token, updating the secret, restarting the service, verifying `/health` internally, and only then revoking the old personal-access-token row.

Every connector request carries a fresh `X-Connector-Nonce` and `X-Connector-Timestamp`; Laravel persists nonce uniqueness per machine token and rejects timestamps outside the configured window. Write retries keep the same `Idempotency-Key` but use a new nonce. Monitor device heartbeat age, event ingestion lag, retry/dead-letter command counts, clock-drift/data-gap anomalies, and `lastError` from the internal `/health` response.

### Required real-hardware pilot (still pending)

1. Read-only BioStar inventory/export and card mapping; do not write to readers.
2. Select one test reader and record model, firmware and live capabilities from Device Gateway.
3. Establish a single system of record; never leave BioStar and this connector as independent writers.
4. Create an employee, issue a test card, confirm the command on the reader, present the card, and verify the immutable event arrives once with the correct epoch/native ID.
5. Disconnect WAN/Laravel while leaving the site LAN and reader online; confirm local door decisions continue and buffered events replay without duplicates after recovery.
6. Revoke the card while the reader is offline; UI must remain pending, never completed. Reconnect and verify acknowledgement before treating access as revoked at that reader.
7. Exercise native-event ID rollover/log overflow and verify epoch handling plus a visible `data_gap` anomaly.
8. Execute the documented rollback to the prior single writer. Record timestamps, device/firmware versions and evidence before enabling any additional reader.
