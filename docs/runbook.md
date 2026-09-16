# ODA CRM — Runbook (skeleton)

This is a skeleton, to be filled in progressively by the Foundation and Integration agents as the real Docker Compose stack, CI pipeline, and operational procedures are built. It exists now so tooling requirements are explicit before any scaffolding starts. See `docs/decisions.md` DEC-001 for why this matters right now.

---

## Required local tooling

| Tool | Target version | Status in this environment (checked 2026-09-16) | Notes |
|---|---|---|---|
| PHP | ^8.3 (8.4 recommended) | **NOT FOUND** (`php -v` → command not found) | Required for Laravel 13. Must be installed with common extensions: `ext-pdo_pgsql`, `ext-mbstring`, `ext-bcmath`, `ext-gd` or `ext-imagick` (for `intervention/image`), `ext-redis` or a Predis fallback, `ext-zip`, `ext-fileinfo`, `ext-curl`, `ext-openssl`, `ext-intl`. |
| Composer | 2.x (latest stable) | **NOT FOUND** (`composer -V` → command not found) | Needed to install Laravel and all PHP dependencies. |
| Node.js | 20.x LTS or newer | **PRESENT** — v24.20.0 detected | Satisfies Vite/Vue tooling requirements. |
| npm | 10.x or newer | **PRESENT** — v11.19.0 detected | |
| PostgreSQL | 16.x server + `psql` client | **NOT FOUND** locally (`psql --version` → command not found) | A local native install is optional if Docker Compose provides Postgres instead (see below) — but *something* reachable from the app must exist before migrations can run. Must support the `btree_gist` extension (used for rate/session exclusion constraints, `docs/data-model.md`) and Row-Level Security (standard in Postgres, no extension needed). |
| Redis | 7.x | **PRESENT** — `redis-cli` 8.10.1 detected (client only; confirm a matching Redis *server* is reachable, not just the CLI) | Used for queues/cache/sessions in non-local environments. |
| Docker + Docker Compose | Current stable | Not checked yet | To be used for the `infra/docker-compose.yml` stack (app, worker, scheduler, postgres, redis, minio, mailhog, device-connector) built during Foundation, per spec section 21's explicit requirement for Docker Compose local orchestration. |

### What this means for sequencing

The Project Manager pass (this document's author) only needed to **write planning documents** — no `composer create-project`, no `artisan` commands, no migrations — so the missing PHP/Composer/Postgres client did not block producing `docs/implementation-plan.md`, `docs/architecture.md`, `docs/data-model.md`, `docs/decisions.md`, or this file.

**The Foundation agent cannot proceed past planning** without PHP and Composer available in its execution environment (to run `composer create-project laravel/laravel` or the official Vue starter kit installer, and every subsequent `artisan` command), and without a reachable PostgreSQL instance (local native install, or via Docker Compose once that's built — but building Docker Compose itself doesn't need PHP, so the Foundation agent's very first move can be authoring `infra/docker-compose.yml` if a container runtime is available, then scaffolding Laravel *inside* that stack rather than on bare metal).

**Per the hard constraint governing this whole build: no agent may install system-wide tooling on its own initiative.** If the Foundation agent starts and finds PHP/Composer/Postgres still unavailable in its execution environment, it must:
1. Not attempt a system-wide install itself.
2. Update this table's "Status in this environment" column with its own fresh check (tools may become available between agent runs, e.g., if the human operator installs them).
3. If still missing, add a dated entry to `docs/decisions.md` describing exactly what's missing and what it blocks, and stop — returning that as its result rather than fabricating scaffold output it couldn't actually run.

### Suggested install approach for the human operator (informational only — no agent runs these automatically)

- **PHP + Composer (Windows)**: install PHP 8.4 (e.g., via the official Windows binaries or a distribution like Laragon/XAMPP that bundles a compatible version), enable the extensions listed above in `php.ini`, then install Composer via its official Windows installer (`Composer-Setup.exe`).
- **PostgreSQL**: either a native Windows installer (postgresql.org) for local development outside containers, or rely entirely on the `postgres` service inside `infra/docker-compose.yml` once Foundation builds it (requires Docker Desktop for Windows).
- **Docker Desktop**: needed either way for the full local stack (Redis, MinIO, mailhog, device-connector) described in `docs/architecture.md` § 1.

---

## Environments

- **dev**: local machine, Docker Compose stack (once built), no seed data beyond RBAC roles/permissions and reference lookups — spec section 21 explicitly forbids production seed data, and dev should mirror that discipline as closely as practical while still being usable for manual testing (factories/seeders clearly marked dev-only).
- **staging**: mirrors production configuration; used for the section 21 performance targets (p95 ≤ 800ms list/read, mobile LCP ≤ 2.5s, event-to-UI lag p95 ≤ 10s) and for the section 17 mandatory real-device PWA install acceptance tests. Hosting provider not yet chosen — see `docs/decisions.md` § Open business-policy questions #6.
- **production**: no seed data; real backups (encrypted DB backup + PITR where the chosen host supports it, object versioning/backup for attachments, target RPO ≤ 15 min / RTO ≤ 4h to be confirmed once hosting is chosen); restore drills documented once infra exists.

---

## CI pipeline (to be implemented by Foundation, REQ-FND-23)

Planned stages, per spec section 21 ("CI: lint, typecheck, migrations, tests, build"):
1. PHP lint / static analysis (e.g. Pint + a static analyzer if added later — record as a Pending dependency if introduced).
2. Frontend typecheck (`vue-tsc`/`tsc --noEmit`).
3. Run migrations against an ephemeral CI Postgres service (with RLS + `btree_gist` enabled).
4. Pest test suite (`php artisan test`), including the RLS-real-role integration tests.
5. Frontend unit tests (Vitest) and production build (`npm run build`).
6. (Later phases) Playwright E2E.

---

## Backups & restore (to be filled in once hosting is chosen)

Placeholder — do not fabricate specifics ahead of an actual hosting decision (`docs/decisions.md` § Open business-policy questions #6). Once chosen, this section documents: backup schedule, encryption method, PITR mechanism, object-storage versioning/backup for `attachments`, retention period, and the periodic restore-drill procedure and its last-run date/result, per spec section 21's explicit requirement that migrations have a backup + rollback/forward-fix plan.

---

## Health / monitoring (to be filled in by Foundation + Integration)

Placeholder for: `/health` and `/ready` endpoint behavior, worker graceful-shutdown behavior, and the operational dashboards for device heartbeat, event ingestion lag, command retry counts, dead-letter queue depth, DB health, upload failures, and backup status — per spec section 21's explicit list. UI must distinguish "no data yet" from "confirmed zero," per spec's explicit requirement.

---

## Device-connector operations (to be filled in by the Devices module agent)

Placeholder for: how to start/stop `services/device-connector`, how its Sanctum machine token is provisioned/rotated, how to switch it between Simulator and real-Suprema-adapter mode, and the documented real-hardware pilot test plan referenced in spec section 6/22 (P0 acceptance: employee → card assignment → reader confirmation → event received → revocation confirmed, including outage/replay behavior).
