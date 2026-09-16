# ODA CRM — Decision Log

This is the running log of routine technical decisions made on behalf of the spec (`ODA_CRM_Claude_Code_Spec.md` v1.2), per section 1's instruction that routine decisions are made autonomously and recorded here with a reason. Nothing here invents payroll policy, tax rules, hardware parameters, or external credentials — those stay as explicit open questions (see "Confirmed vs. unconfirmed parameters" in `implementation-plan.md`).

Log format: `DEC-NNN` — decision — reason — date — author (agent name).

---

## Environment / tooling

### DEC-001 — PHP, Composer, and a local PostgreSQL client are not present in this environment
**Decision:** Recorded as a blocking environment gap rather than silently worked around. No system-wide tooling was installed by this (Project Manager) agent, per the hard constraint that only Node.js/npm/PHP/Composer availability is checked and none may be auto-installed.
**Reason:** `php -v`, `composer -V`, and `psql --version` all returned "command not found" when checked on 2026-09-16. `node -v` (v24.20.0), `npm -v` (11.19.0), and `redis-cli --version` (8.10.1) succeeded. The Project Manager's task in this pass is planning-document authorship only (no `composer create-project`, no `artisan` calls, no migrations run), so this does not block producing `docs/implementation-plan.md`, `docs/architecture.md`, `docs/data-model.md`, `docs/decisions.md`, `docs/runbook.md`. It **does** block the Foundation agent, which cannot scaffold Laravel without PHP+Composer, and blocks running real Postgres migrations without a `psql`/Postgres server reachable from this machine (or via Docker).
**Action required before Foundation starts:** see `docs/runbook.md` "Required local tooling" section for exact versions and install guidance. The Foundation agent must re-check tool availability at the start of its run and, if still missing, stop and update this log + the runbook again rather than installing system packages without the user's explicit action.
**Date:** 2026-09-16. **Author:** Project Manager.

---

## Stack selections (spec section 18 mandates the framework; these are the concrete package/version choices within it)

### DEC-002 — Laravel version target: Laravel 13 on PHP 8.4 (minimum PHP 8.3)
**Decision:** Target Laravel 13.x, requiring PHP ^8.3 (recommend PHP 8.4 for local/dev/staging/prod parity).
**Reason:** Spec section 18 mandates Laravel 13 explicitly. Laravel 13 requires PHP 8.3+; PHP 8.4 is the current stable release as of the spec's 2026-09-16 source check and gets the longest active support window. The Foundation agent must re-verify the exact PHP/Laravel/package compatibility matrix at scaffold time (per spec: "Laravel 13-ის თავსებადი PHP/package ვერსიები ... გადაამოწმე განხორციელებისას") and lock via `composer.lock`.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-003 — Frontend stack: Vue 3 + TypeScript + Inertia.js + Vite + Tailwind CSS, via the official Laravel Vue starter kit
**Decision:** Use `laravel/vue-starter-kit` (or the current official Laravel 13 Vue+Inertia+TS starter kit named at scaffold time) as the base, per spec section 18's explicit instruction to use the official Vue starter kit.
**Reason:** Spec section 18 requires it verbatim ("გამოიყენე ოფიციალური Vue starter kit როგორც საფუძველი"). It ships Inertia + TS + Vite + Tailwind pre-wired, session auth scaffolding, and matches the mandated architecture, minimizing bespoke boilerplate risk.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-004 — Database: PostgreSQL, accessed via Eloquent; Redis for queues/cache
**Decision:** PostgreSQL (target 16.x) as the only supported database; Redis (target 7.x) for Laravel queues, cache, and session store in non-local environments.
**Reason:** Mandated by spec section 18 ("მონაცემთა ბაზა PostgreSQL"; "Redis-backed Laravel queues"). PostgreSQL is also required for the Row-Level Security (RLS) defense-in-depth layer for tenant isolation (section 18/21), which MySQL does not offer natively.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-005 — Permissions package: `spatie/laravel-permission`
**Decision:** Use `spatie/laravel-permission` (record exact version under "Pending dependencies" below) for role/permission storage, layered under Laravel Policies/Gates for the actual authorization checks.
**Reason:** It is the de-facto standard Laravel RBAC package: mature, actively maintained, supports multiple roles per user (spec section 3: "მომხმარებელს შეიძლება რამდენიმე როლი ჰქონდეს"), teams/tenant-scoping guard support (usable to additionally scope roles per organization_id if needed later), and integrates cleanly with Policies so permission checks remain server-side per the hard constraint ("მხოლოდ მენიუს დამალვა არ არის დაცვა"). It does not replace Policies — every controller action still goes through a Policy/Gate that also enforces `organization_id` and project-membership scoping (spec section 3: role + project membership jointly determine access), which spatie's package alone does not model.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-006 — QR code library: `simple-qrcode` (bacon/bacon-qr-code wrapper) for generation; native browser `BarcodeDetector`/a JS library for scanning
**Decision:** Server-side QR generation via `simplesoftwareio/simple-qrcode` (wraps `bacon/bacon-qr-code`), producing SVG/PNG codes that encode only an opaque asset reference (e.g. a UUID or short token), never a direct unauthenticated deep link to asset data. Client-side scanning in the Vue/PWA app via the browser's native `BarcodeDetector` API where available, falling back to a small JS QR-scanning library (exact package to be named by the Tools/Assets module agent under "Pending dependencies" if the native API's browser coverage proves insufficient).
**Reason:** Spec section 9 requires "QR კოდი ხსნის უფლებებით დაცულ აქტივის გვერდს; QR-ის ცოდნა არ იძლევა წვდომას" — i.e., the QR payload must not itself be a bearer credential. Resolving the QR value to asset data must always re-run the normal Policy check server-side. `bacon/bacon-qr-code` is the standard pure-PHP QR encoder with no external service dependency (keeps generation server-side and offline-capable), which matters for a PWA used on construction sites with unreliable connectivity.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-007 — Image processing: `intervention/image` (v3, GD or Imagick driver)
**Decision:** Use `intervention/image` v3 for server-side photo preview/thumbnail generation, EXIF stripping, and re-encoding of uploaded photos.
**Reason:** Spec section 21 requires compressed previews and a defined original-retention policy, and section 10 notes EXIF/photo timestamps are unverified metadata (so EXIF should not be trusted, and stripping it on the served preview avoids leaking device/location metadata unintentionally). `intervention/image` is the standard Laravel-ecosystem image library; v3 supports both GD and Imagick drivers, letting the Integration agent pick whichever the target host has available without changing calling code.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-008 — Testing framework: Pest (PHP), Vitest (frontend unit), Playwright (E2E)
**Decision:** Pest (built on PHPUnit) for all backend Feature/Unit tests under `tests/Feature/<Module>` and `tests/Unit/<Module>`. Vitest for Vue/TS component/unit tests. Playwright for the cross-role E2E scenarios required by section 23 (desktop + mobile viewport emulation at minimum; real-device PWA install tests are manual per section 17's acceptance tests and cannot be automated in CI).
**Reason:** Spec section 18 explicitly allows "Pest ან PHPUnit" — Pest is chosen for its more readable syntax and first-class Laravel integration (`pest-plugin-laravel`). Vitest pairs natively with the Vite build already mandated. Playwright is chosen over Cypress for built-in multi-browser + mobile-viewport emulation and better CI parallelization; it cannot substitute for the mandatory real-Android-Chrome / real-iPhone-Safari PWA install acceptance tests in section 17, which remain manual and must be reported as such.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-009 — API/machine authentication: Laravel Sanctum
**Decision:** Laravel Sanctum for (a) SPA/Inertia session-based auth (cookie + CSRF, first-party), and (b) scoped personal-access tokens for machine identities — specifically the `services/device-connector` process and any future server-to-server integration (e.g., BioStar export ingestion).
**Reason:** Spec section 18 requires "session authentication and CSRF protection ... machine/API auth with scoped tokens as needed" and section 20 requires webhook/connector ingestion to use "a separate machine identity with replay protection and request validation." Sanctum natively supports both patterns in one package without adding OAuth2 (Passport) complexity that this single-tenant-per-deployment, first-party-only integration setup does not need.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-010 — File storage: private disk (S3-compatible), signed short-lived URLs
**Decision:** All uploads (photos, documents, attachments) go to a private, non-public disk configured via Laravel's `filesystems` config against an S3-compatible backend (exact provider to be confirmed at deployment time — local `staging` disk driver acceptable for dev/CI). Downloads always go through a signed, short-lived URL issued only after a Policy check, per spec section 20/21.
**Reason:** Directly mandated: "ფაილები private; signed download link მოკლე ვადით და წვდომის შემოწმების შემდეგ." Using Laravel's built-in S3 filesystem driver keeps this swappable between MinIO (local/dev), and a real S3-compatible provider (staging/prod) without app code changes.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-011 — Money type: Postgres `numeric(14,2)` for GEL amounts via a custom Eloquent cast backed by `brick/money` (decimal), never float
**Decision:** All monetary columns are `numeric(14,2)` (or `numeric(18,4)` for intermediate rate/unit-price columns that need more precision before final rounding) in Postgres, mapped through a custom Eloquent attribute cast that stores/returns PHP string/`Brick\Money\Money` values — never native float/double.
**Reason:** Hard constraint: "All money uses exact decimal types, never float." `brick/money` (pure-PHP, no ext-bcmath hard requirement beyond what it bundles) gives correct decimal arithmetic and documented rounding-mode support, satisfying spec section 8's requirement for a documented half-up rounding rule at final GEL rounding and deterministic remainder allocation across project splits.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-012 — Primary keys: UUID v7 (or ULID) as public identifiers, bigint internal identity optional
**Decision:** Every table's primary key is a UUID (v7, time-ordered, via `symfony/uid` or Laravel's built-in `HasUuids`/`HasVersion7Uuids` trait) used directly as both the internal PK and the public-facing identifier. No separate internal-vs-public ID split, to avoid duplicate-identifier bugs; UUIDv7 is chosen over v4 for better index locality (time-ordered) given the section 21 target of 10M+ events.
**Reason:** Spec section 18: "UUID-ები public identifiers-ად." UUIDv7's monotonic-ish ordering avoids the B-tree fragmentation problem of random UUIDv4 at the event-table scale the spec targets (10 million events).
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-013 — Every table: `created_at`, `updated_at`, optimistic-lock `version` column (spec-mandated), soft-deletes only on reference data
**Decision:** All Eloquent models get standard timestamps plus an integer `version` column incremented on every update (used for the optimistic-concurrency / "approval target version" rule in section 19 and the version-conflict UX in section 4). Soft-deletes (`deleted_at`) are added only to reference/catalog-type tables (e.g. Client, Item, ShiftTemplate); all financial, inventory-ledger, and audit tables are append-only with explicit reversal/adjustment rows and never use soft-delete or hard delete.
**Reason:** Directly mandated by section 18 ("created_at/updated_at/version"; "Soft-delete მხოლოდ საცნობარო მონაცემებისთვის; ფინანსურ/მარაგის/აუდიტის ledger-ში append/reversal").
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-014 — Outbox pattern: `outbox_events` table written in the same DB transaction as the business change; a scheduled/queued relay dispatches to Redis queue jobs
**Decision:** Any state change that must trigger a side effect outside the current transaction (device sync commands, notifications, cross-module reactions) inserts an `OutboxEvent` row in the same transaction as the business write. A relay worker (queued job on a short recurring schedule, or triggered post-commit via `DB::afterCommit`) reads unprocessed outbox rows and dispatches idempotent Redis queue jobs.
**Reason:** Mandated: "ცვლილება და outbox event ერთ DB transaction-ში; queue jobs idempotent." This guarantees no side effect is scheduled for a change that later rolls back, and no change silently fails to schedule its side effect.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-015 — Idempotency: dedicated `idempotency_records` table keyed by (organization_id, endpoint, idempotency_key), storing a request payload hash and cached response
**Decision:** All money/inventory/submission-mutating POST endpoints require an `Idempotency-Key` header. Middleware checks `idempotency_records`; same key + same payload hash replays the cached response; same key + different payload hash returns 409 Conflict, per spec section 20.
**Reason:** Directly mandated by section 20: "იგივე გასაღები განსხვავებული payload-ით იწვევს conflict-ს."
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-016 — Multi-tenancy enforcement: global Eloquent scope + Postgres RLS, tenant id derived server-side from session/auth guard only
**Decision:** (1) A global Eloquent scope (`BelongsToOrganization`) automatically applies `WHERE organization_id = ?` to every tenant-scoped model query, with the value pulled only from `Auth::user()->current_organization_id` (or the authenticated machine token's bound organization for `services/device-connector`) — never from any client-supplied field, header, or route parameter, which are ignored if present. (2) Defense-in-depth: Postgres Row-Level Security policies on every business table, enforced via a non-superuser runtime DB role that the app connects as (RLS is bypassed by table owners/superusers in Postgres, so the app's runtime role must NOT be the table owner). Session-scoped `SET LOCAL app.current_org_id = '<uuid>'` per request/transaction, with RLS policies referencing that setting. (3) A dedicated Pest integration test suite connects as the actual restricted runtime role (not a superuser bypassing RLS) and asserts cross-tenant rows are truly invisible at the SQL level, satisfying the spec's "tested with a real runtime DB role" requirement.
**Reason:** Directly mandated by sections 18/21/23 ("Cross-tenant FK/unique constraints და ცენტრალიზებული authorization policy; defense-in-depth-ისთვის PostgreSQL RLS, რომელიც ტესტდება რეალური runtime DB როლით"; acceptance test "სხვა tenant-ის ID job/export/API-ში → წვდომა უარყოფილია").
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-017 — Local dev/CI orchestration: Docker Compose (app, postgres, redis, mailhog, minio, device-connector)
**Decision:** `infra/docker-compose.yml` (built during the Foundation phase) will define services: `app` (PHP-FPM + Laravel), `worker` (queue worker), `scheduler`, `postgres`, `redis`, `minio` (S3-compatible local storage), `mailhog` (local mail capture), and `device-connector` (the Suprema simulator/adapter process). Vite dev server runs via `npm run dev` outside or inside Compose depending on developer preference; exact wiring finalized by the Foundation agent.
**Reason:** Mandated by section 21 ("Docker Compose ადგილობრივი გაშვებისთვის") and needed to give every module-building agent an environment where Postgres RLS and Redis queues actually work, rather than developing against SQLite/array drivers that don't exercise the real constraints.
**Date:** 2026-09-16. **Author:** Project Manager.

---

## Module Contribution Convention decisions (see docs/architecture.md for full mechanics)

### DEC-018 — Route files: bootstrap-loop + per-module route files
**Decision:** `routes/api.php` and `routes/web.php` contain ONLY a glob-and-require bootstrap loop (written once, during Foundation) over `routes/modules/api-*.php` and `routes/modules/web-*.php`. No agent may edit `routes/api.php`/`routes/web.php` after Foundation creates them.
**Reason:** Prevents concurrent-edit merge conflicts between module-building agents working in parallel, per the task's explicit requirement.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-019 — Navigation: config-driven per-module registry, no shared component edits
**Decision:** A single `NavigationService` (built during Foundation) aggregates entries from `config/modules/<module>-nav.php` files, filters by the current user's resolved permissions at render time, and feeds one shared Vue nav component. Modules add only their own `config/modules/<module>-nav.php`; nobody edits the shared nav Vue component or the aggregating service after Foundation.
**Reason:** Same concurrency-safety rationale, applied to navigation; also directly serves the hard constraint that menu visibility must reflect real permissions (the filter step queries the same Policy/Gate layer, not a separate hardcoded list).
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-020 — Permissions/roles seeding: per-module seeder files, auto-discovered
**Decision:** A base RBAC seeder (`RbacBaseSeeder`, Foundation) creates the roles from section 3's table. Each module ships `database/seeders/modules/<Module>PermissionsSeeder.php` declaring only that module's permissions and role-grants; a single `AggregatingPermissionsSeeder` (Foundation) globs and calls all of them in a fixed, documented order.
**Reason:** Same concurrency-safety rationale, applied to seeders.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-021 — Dependencies: modules code against a named package+version, Integration agent installs once
**Decision:** No module-building agent edits `composer.json`/`package.json`/lockfiles. If a module needs a package not already installed, it writes code against that package's real public API and appends an entry under "Pending dependencies" (below) naming the exact package and version constraint. The Integration agent installs everything in one pass at the end and fixes any version-conflict fallout.
**Reason:** Directly required by the task; avoids N agents each running `composer require` and clobbering each other's lockfile.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-022 — Migrations: additive-only after Foundation, nobody but Integration runs `migrate`
**Decision:** All P0+P1 tables are created once, during Foundation, as the initial migration set. A later module agent that finds a genuine gap creates a NEW migration file (never edits an existing one) to add the missing column/table/index, but does not run `php artisan migrate` itself — the Integration agent runs migrations once, in order, at the end of each phase.
**Reason:** Directly required by the task; prevents partial/duplicate schema application and migration-history divergence across agents working from possibly-stale local DB state.
**Date:** 2026-09-16. **Author:** Project Manager.

### DEC-023 — Testing: module agents run only their own filtered Pest file; Integration runs the full suite
**Decision:** Each module agent writes and self-verifies only `tests/Feature/<Module>/**` and `tests/Unit/<Module>/**` (via `php artisan test --filter=<Module>` or a Pest group tag). The Integration agent runs the entire suite (`php artisan test`) plus frontend typecheck/build at the end of each phase and is responsible for fixing cross-module breakage.
**Reason:** Directly required by the task; keeps each module agent's feedback loop fast and scoped to what it can actually fix.
**Date:** 2026-09-16. **Author:** Project Manager.

---

## Pending dependencies

Later agents append here: exact package name + version constraint + which module needs it + why. The Integration agent consumes this whole section in one pass, runs `composer require`/`npm install` accordingly, resolves conflicts, and then updates each entry's status to "Installed (vX.Y.Z)".

_(empty — nothing pending yet as of Project Manager hand-off, 2026-09-16)_

| Package | Version constraint | Ecosystem | Requested by (module) | Reason | Status |
|---|---|---|---|---|---|
| — | — | — | — | — | — |

---

## Open business-policy questions (must NOT be guessed — see spec section 2 "ჯერჯერობით უცნობია")

These are tracked here so no later agent invents an answer. Each must be resolved by the business/product owner before the related feature can be marked production-ready; until then the corresponding config defaults to a safe, clearly-labeled placeholder and the feature stays flagged as pending confirmation in the phase completion report (spec section 25).

1. Exact employee and device/reader count at real deployment sites (affects indexing/partitioning tuning, not correctness).
2. Physical in/out reader topology per site (single vs. paired IN/OUT readers) — direction inference logic must read this from `Device.reader_role` config per site, never assume "every second read is OUT" (explicitly forbidden by spec section 7).
3. Actual Suprema device firmware versions in the field, and whether BioStar 2 is currently the system of record for any live door — blocks real hardware go-live, not simulator development.
4. Night-shift, break, and daily-rate rounding/threshold policy (full/half-day cutoff, minimum attendance, incomplete-day handling) — spec section 8 explicitly forbids hardcoding this; it must be a configurable `ShiftTemplate`/`DailyPayPolicy` record confirmed by an accountant before production activation.
5. Statutory overtime/holiday coefficients and tax withholding — explicitly out of scope for the internal P1 accrual ledger (spec section 8); a real payroll/tax engine integration is a separate future project.
6. Hosting provider/target infrastructure for staging/production, and therefore concrete backup RPO/RTO achievability (section 21 target: RPO ≤ 15 min, RTO ≤ 4h, to be confirmed against actual infra, not assumed).
7. File storage retention policy for original (pre-compression) photos/documents.
8. Legal retention periods for audit/financial records — do not invent specific legal deadlines; get counsel confirmation before finalizing `AuditEvent`/ledger retention config.
