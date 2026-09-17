# ODA CRM — Architecture

Source of truth: `ODA_CRM_Claude_Code_Spec.md` v1.2, section 18 (mandatory architecture) plus sections 1–4, 19–21. This document is the concrete technical decision set every later agent builds against. It does not restate the spec — read section 18 directly for the framework mandate; this file resolves it into specific packages, repo layout, and — critically — the **Module Contribution Convention** that lets many agents build in the same repo without stepping on each other.

Related: `docs/decisions.md` (why each choice was made, plus the running "Pending dependencies" list), `docs/data-model.md` (concrete schema), `docs/implementation-plan.md` (backlog + agent assignment), `docs/runbook.md` (tooling/setup).

---

## 1. Stack

| Layer | Choice | Notes |
|---|---|---|
| Backend framework | Laravel 13 (PHP ^8.3, target 8.4) | Mandatory per spec section 18. Modular monolith — one Laravel app, domain-separated internally, not microservices. |
| Frontend | Vue 3 + TypeScript + Inertia.js + Vite + Tailwind CSS | Scaffolded from the official Laravel Vue starter kit (`laravel/vue-starter-kit` or current equivalent named at scaffold time — Foundation agent confirms exact package name/version against `https://laravel.com/framework/docs/13.x/starter-kits`). |
| Database | PostgreSQL 16.x | Eloquent ORM, migrations, DB-level constraints/transactions are the enforcement layer, not just app code. |
| Queues / cache / sessions (non-local) | Redis 7.x | Laravel queues backed by Redis; horizon or plain `queue:work` supervisord processes — Foundation decides based on ops simplicity. |
| Auth | Laravel session auth (cookie + CSRF) for Inertia pages; Laravel Sanctum personal-access tokens for `/api/v1` machine clients (device-connector, future integrations) | See `docs/decisions.md` DEC-009. |
| Authorization | Laravel Policies/Gates (server-side, always) + `spatie/laravel-permission` for role/permission storage | See DEC-005. Policies are the actual enforcement point; the permission package only backs "does this user have permission X" lookups that Policies call. |
| File storage | Private disk, S3-compatible (MinIO locally, real S3-compatible provider in staging/prod) | Signed short-lived download URLs only, issued after a Policy check. See DEC-010. |
| Money | Postgres `numeric`, PHP `brick/money` decimal type via custom Eloquent cast | Never float. See DEC-011. |
| QR codes | `simplesoftwareio/simple-qrcode` (server-generation) + native `BarcodeDetector` (client-scan) | See DEC-006. |
| Image processing | `intervention/image` v3 | See DEC-007. |
| Testing | Pest (PHP feature/unit), Vitest (Vue/TS unit), Playwright (E2E, viewport-emulated; real-device PWA install tests remain manual) | See DEC-008. |
| Local orchestration | Docker Compose (`infra/docker-compose.yml`) | app, worker, scheduler, postgres, redis, minio, mailhog, device-connector. Built during Foundation. |
| Suprema integration | Separate OS process, `services/device-connector`, speaking Suprema G-SDK / Device Gateway | Never embedded in the Laravel process; communicates with Laravel over a versioned, authenticated API/queue contract. See section 6 below. |

---

## 2. Repository layout

```
/
├── app/
│   ├── Domain/<Module>/            # business logic: Actions, Services, DTOs, Events, Listeners, ValueObjects
│   │   ├── Actions/
│   │   ├── Services/
│   │   ├── DataTransferObjects/
│   │   ├── Events/
│   │   └── Models/                 # Eloquent models owned by this module
│   ├── Http/
│   │   ├── Controllers/<Module>/   # thin controllers: validate request -> call Domain Action -> return Inertia/JSON
│   │   ├── Requests/<Module>/      # FormRequest validation classes
│   │   ├── Resources/<Module>/     # API Resource / JSON transformers for /api/v1
│   │   └── Middleware/             # shared middleware only — see ownership rules below
│   ├── Policies/                   # one Policy class per Model, named <Model>Policy — see ownership rules
│   ├── Jobs/                       # queued jobs, one subfolder per module, all idempotent
│   ├── Console/Commands/
│   └── Support/                    # cross-cutting helpers with NO business rules (money formatting, ULID/UUID helpers, etc.)
├── config/
│   └── modules/
│       ├── <module>-nav.php        # one file per module — nav entries only, owned by that module
│       └── <module>.php            # module-specific config, owned by that module
├── database/
│   ├── migrations/                 # see migration ownership rules — additive only after Foundation
│   ├── seeders/
│   │   ├── modules/<Module>PermissionsSeeder.php   # one per module
│   │   └── DatabaseSeeder.php + AggregatingPermissionsSeeder.php (Foundation-owned)
│   └── factories/<Module>/
├── resources/
│   ├── js/
│   │   ├── Pages/<Module>/         # Inertia page components, one folder per module
│   │   ├── Components/             # SHARED components — see ownership rules (design-system primitives only)
│   │   ├── Components/<Module>/    # module-owned components used only within that module's pages
│   │   ├── Composables/
│   │   ├── Layouts/                # shared shell layouts — Foundation-owned
│   │   └── lib/                    # shared TS utilities (api client, formatting)
│   └── css/
├── routes/
│   ├── api.php                     # BOOTSTRAP LOOP ONLY — see ownership rules
│   ├── web.php                     # BOOTSTRAP LOOP ONLY — see ownership rules
│   └── modules/
│       ├── api-<module>.php        # one per module (if it exposes /api/v1 resources)
│       └── web-<module>.php        # one per module (if it has Inertia pages)
├── tests/
│   ├── Feature/<Module>/
│   └── Unit/<Module>/
├── docs/
│   ├── implementation-plan.md
│   ├── architecture.md             # this file
│   ├── data-model.md
│   ├── decisions.md
│   └── runbook.md
├── infra/
│   ├── docker-compose.yml
│   └── docker/                     # Dockerfiles per service
└── services/
    └── device-connector/           # separate process, own package.json/composer.json/go.mod as chosen at build time
        ├── src/
        ├── README.md               # its own API/queue contract documented here
        └── (its own test suite)
```

`<Module>` names used consistently across all four locations (Domain, Http/Controllers, resources/js/Pages, tests) are: `Auth`, `Employees`, `Devices`, `Attendance`, `Timesheets`, `Payroll`, `Assets`, `Projects`, `Tasks`, `DailyJournal`, `Notifications`, `Shared` (cross-cutting, Foundation/Integration-owned only). Exact mapping to agents is in `docs/implementation-plan.md` § Agent assignment.

---

## 3. Module Contribution Convention — MANDATORY, read before writing any code

This exists so that N module-building agents can work through the backlog largely independently without merge conflicts on shared files. **Violating any rule below is treated as a build defect**, not a style nit — a later agent finding a shared file edited outside these rules must revert the shared-file portion of that change and re-implement it through the correct extension point.

### 3.1 Routes

- `routes/api.php` and `routes/web.php` are created ONCE, by the Foundation agent, and forever after contain **only** a bootstrap loop, e.g.:

```php
// routes/api.php
foreach (glob(__DIR__.'/modules/api-*.php') as $file) {
    require $file;
}
```

  (and equivalently for `routes/web.php` requiring `routes/modules/web-*.php`). Sort order is filename-alphabetical; if a module ever needs guaranteed ordering relative to another, that dependency must be expressed as an explicit route-name check inside the module's own file, not by renaming files to force load order.
- Every module owns exactly one `routes/modules/api-<module>.php` (all its `/api/v1/...` routes, wrapped in that module's own middleware group) and, if it has Inertia pages, exactly one `routes/modules/web-<module>.php`.
- **No module-building agent may ever edit `routes/api.php` or `routes/web.php`** after Foundation creates the bootstrap loop. Only the Integration agent may touch those two files again, and only to fix the bootstrap mechanism itself (never to add a route directly).

### 3.2 Navigation

- A single `NavigationService` (`app/Domain/Shared/Services/NavigationService.php`, Foundation-owned) collects nav entries by loading every `config/modules/<module>-nav.php`, merging their arrays, and filtering each entry by `Gate::allows(...)` / the current user's resolved permissions and project memberships (server-side, at render time — this is the actual enforcement point, not just visual hiding, per the hard constraint). **Implemented (Foundation verification pass, 2026-09-16, DEC-071):** see `config/modules/shared-nav.php` for the reference nav-file shape (`[{group, items: [{label, icon, route, permission}]}]`), wired into every Inertia response via `HandleInertiaRequests`'s `navGroups` shared prop and rendered by `resources/js/components/AppSidebar.vue` (icon strings resolved via `resources/js/lib/navIcons.ts`). A module adds a nav entry only by shipping its own `config/modules/<module>-nav.php` — it does not edit `NavigationService`, `AppSidebar.vue`, or `navIcons.ts`'s existing entries (it may add a new icon key there if needed).
- Each module's `config/modules/<module>-nav.php` returns an array of entries: label (Georgian), icon, route name, required permission(s), and desktop-menu group (mapped to the section-4 menu groups: მიმოხილვა/პროექტები/დავალებები/თანამშრომლები/დასწრება/ანაზღაურება/ხელსაწყოები/საწყობი/შესყიდვები/ფინანსები/კლიენტები/ხარისხი და უსაფრთხოება/დოკუმენტები/ანგარიშები/პარამეტრები) plus, where relevant, a mobile bottom-nav slot (max 5 items total across the whole app — Foundation reserves and documents which 5 slots exist; a module cannot unilaterally add a 6th).
- **No module may edit the shared sidebar/bottom-nav Vue component directly.** It only ever adds/edits its own `config/modules/<module>-nav.php`.

### 3.3 Permissions / roles

- Foundation creates `database/seeders/RbacBaseSeeder.php`, which creates the roles from spec section 3 (მფლობელი/დირექტორი, სისტემური ადმინისტრატორი, HR, ფინანსისტი, პროექტის მენეჯერი, ბრიგადირი, საწყობის პასუხისმგებელი, თანამშრომელი, შესყიდვების მენეჯერი, ხარისხის/უსაფრთხოების სპეციალისტი, კლიენტი/ქვეკონტრაქტორი) as `spatie/laravel-permission` `Role` records, and `database/seeders/AggregatingPermissionsSeeder.php`, which globs `database/seeders/modules/*PermissionsSeeder.php` and calls each in a fixed, explicitly documented order (documented as a numbered list inside the aggregating seeder's docblock — Foundation assigns each module a load-order number when it registers).
- Each module ships exactly one `database/seeders/modules/<Module>PermissionsSeeder.php` that creates only that module's own permissions (naming convention: `<module>.<resource>.<action>`, e.g. `employees.rates.view`, `payroll.pay-runs.approve`) and attaches them to the roles from section 3's table that the spec says should have them. A module seeder must NOT create or edit Role records — only Permission records and role-permission attachments for roles Foundation already created.
- **No module edits `RbacBaseSeeder.php` or `AggregatingPermissionsSeeder.php` directly** — only adds its own file under `database/seeders/modules/`.
- Every Policy method must check BOTH the permission (via the role) AND — for anything project/employee-scoped — the actual `ProjectMembership`/ownership relationship, per spec section 3 ("პროექტის წევრობა და როლის უფლებები ერთად განსაზღვრავს წვდომას"). A permission alone is never sufficient for project-scoped resources.

### 3.4 Dependencies

- No module-building agent edits `composer.json`, `package.json`, or any lockfile.
- If a module needs a package not already installed, it still writes real code against that package's actual documented public API (never a guessed/fabricated API), and appends one row to the "Pending dependencies" table in `docs/decisions.md` naming: exact package name, version constraint, which module needs it, and why.
- The Integration agent installs everything from that table in one pass at the end of each phase, resolves any version conflicts, runs the full test suite, and marks each row "Installed (vX.Y.Z)".

### 3.5 Migrations

- All P0+P1 tables (the full data model in `docs/data-model.md`) are created ONCE, during the Foundation phase, as the initial migration set under `database/migrations/`.
- After Foundation, a module-building agent that discovers a genuinely missing column/table/index does not edit an existing migration file — it creates a NEW, additive migration file (e.g. `2026_xx_xx_add_x_to_y_table.php`) and documents why in that migration's docblock and in `docs/decisions.md` if it constitutes a routine technical decision.
- No module-building agent runs `php artisan migrate` (or `migrate:fresh`, `migrate:rollback`) against any shared database. Only the Integration agent runs migrations, once per phase, in filename order, against the shared dev/CI database, and reports the result.
- Local iteration by a module agent (e.g., verifying its own migration applies cleanly) must use its own isolated SQLite/ephemeral Postgres test database (Pest's default test DB config), never the shared dev database.

### 3.6 Testing

- Each module-building agent writes Pest tests under `tests/Feature/<Module>/` and `tests/Unit/<Module>/` only, and self-verifies with a filtered run, e.g. `php artisan test --filter=Employees` or a Pest `group('employees')` tag — never a full-suite run that could mask another module's pre-existing failures as its own responsibility.
- The Integration agent runs `php artisan test` (full suite), the frontend typecheck (`vue-tsc`/`tsc --noEmit`), `npm run build`, and Playwright E2E at the end of each phase, and is responsible for fixing cross-module breakage before sign-off.

### 3.7 Shared Vue components and layouts

- `resources/js/Components/` (no module subfolder) holds only true design-system primitives (buttons, inputs, table shell, modal, toast, empty/error/loading/offline state components, KPI tile) — these are Foundation-owned. A module needing a new shared primitive proposes it by adding it under its own `resources/js/Components/<Module>/` first; promoting something to shared status is an Integration-agent decision, not something a module agent does unilaterally by editing a file in the shared folder.
- `resources/js/Layouts/` (desktop shell, mobile shell, auth shell) are Foundation-owned and not edited by module agents.

### 3.8 Middleware, service providers, `bootstrap/app.php`

- Same rule as routes: `bootstrap/app.php` and `bootstrap/providers.php` are Foundation-owned files, edited exactly once more than their initial scaffold (to add the one aggregator line below) and never again by a module-building agent. **Mechanism, implemented by the Auth/RBAC/Tenancy pass (2026-09-16):** `bootstrap/providers.php` lists `App\Providers\ModuleServiceProviderAggregator::class` once, alongside the starter kit's own explicitly-listed `AppServiceProvider`/`FortifyServiceProvider`. That aggregator globs `app/Providers/<Module>/<Module>ModuleServiceProvider.php` (the class name MUST end in `ModuleServiceProvider`, distinguishing it from framework/starter-kit providers that stay explicitly listed) and registers each one it finds via `is_subclass_of($fqcn, ServiceProvider::class)` — a module needing to register a policy binding, an event listener, a custom auth provider, a Gate, or middleware into an existing shared group does so entirely inside its own `app/Providers/<Module>/<Module>ModuleServiceProvider.php`, never by editing `bootstrap/app.php`, `bootstrap/providers.php`, or another module's provider. Use `$this->app->make(Kernel::class)->prependMiddlewareToGroup(...)` for middleware that establishes request context needed by route-model binding (notably tenancy); ordinary post-binding middleware may use `appendMiddlewareToGroup(...)`. See `app/Providers/Auth/AuthModuleServiceProvider.php` for the reference implementation.

---

## 4. Multi-tenancy

- Every business table has a non-nullable `organization_id` (UUID, FK to `organizations.id`).
- **Server-side derivation only**: `organization_id` for a write is always taken from `Auth::user()->current_organization_id` (resolved at login / org-switch, stored on the session) for interactive users, or from the authenticated Sanctum token's bound organization for machine clients (`services/device-connector`). Any `organization_id` present in a client request payload is ignored/stripped by the FormRequest layer before it reaches a Domain Action — it is never trusted, per the hard constraint.
- **Global Eloquent scope**: a `BelongsToOrganization` trait + global scope applied to every tenant-scoped model automatically adds the `organization_id` predicate to every query built through Eloquent, and automatically stamps it on create.
- **Defense-in-depth — Postgres RLS**: every business table gets an RLS policy (`USING (organization_id = current_setting('app.current_org_id')::uuid)`), enabled with `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` (and `FORCE ROW LEVEL SECURITY` so even the table owner isn't exempt within normal queries). The Laravel DB connection runs as a dedicated non-superuser, non-table-owner Postgres role (`oda_app`) for this to have any effect — RLS is a no-op for superusers/table owners. A request-scoped middleware issues `SET LOCAL app.current_org_id = ?` at the start of each request's DB transaction.
- **RLS is tested with a real runtime DB role**, not asserted by policy definition alone: a Pest integration test suite connects using the actual `oda_app` role credentials (not the migration/superuser role) and asserts that a query for tenant B's data while `app.current_org_id` is set to tenant A returns zero rows, satisfying spec section 18/23's explicit requirement and the acceptance test "სხვა tenant-ის ID job/export/API-ში → წვდომა უარყოფილია; არც ერთი მონაცემი არ ჟონავს."
- Background jobs, scheduled exports, and attachment access all re-derive and re-check `organization_id` from the job's stored context (never re-trust a payload), and re-establish the `SET LOCAL` for their own DB session since queue workers don't inherit the web request's transaction.
- Cross-tenant uniqueness/FK constraints: composite unique constraints and FKs that involve tenant-scoped rows always include `organization_id` in the key (e.g., `unique(organization_id, employee_code)`), so a stray cross-tenant FK reference is a DB-level constraint violation, not just an app-level check.

---

## 5. Conventions: money, decimals, UUIDs, timestamps, audit, outbox

- **Money**: `numeric(14,2)` columns (or `numeric(18,4)` for pre-rounding intermediate rate math), never `float`/`double`. PHP-side, monetary values are handled via `brick/money`-backed custom Eloquent casts. Final GEL amounts round half-up to 0.01 (spec section 8), documented at the point of rounding. Deterministic remainder allocation (largest-remainder method) when splitting a total across projects/lines so the parts always sum exactly to the original total.
- **Quantities**: `numeric` with a defined precision per unit type (see `docs/data-model.md` § Item/Unit); unit conversions are explicit ratios stored on `UnitConversion`, never inferred.
- **UUIDs**: UUIDv7 primary keys everywhere (see DEC-012), used as the only identifier (no separate internal integer PK).
- **Timestamps**: all `*_at` columns stored in UTC (`timestamptz` in Postgres). Any "local business date" (e.g., attendance work-date, daily report date) is a separate `date`-typed column derived using the site's/organization's configured timezone (default `Asia/Tbilisi`), never derived ad-hoc from a UTC timestamp at render time.
- **Versioning**: every table has an integer `version` column, incremented on update via an Eloquent saving hook. Approval-type actions (Timesheet approve, PayRun approve, TaskAcceptance, CredentialAssignment changes) store the `target_version` they approved; if the underlying draft changed since, the approval is rejected as stale (409), per spec section 19's explicit rule.
- **Soft-delete**: enabled only on reference/catalog tables (Client, Item, ShiftTemplate, CostCode, Supplier, etc. — see `docs/data-model.md` for the per-table decision). Financial ledgers (PayRunLine, Payment, StockMovement, CostLedgerEntry), inventory ledgers, and AuditEvent are strictly append-only; corrections are new reversal/adjustment rows referencing the original, never delete or silent update.
- **Audit**: an `AuditEvent` row is written (via a Domain-layer concern, not scattered manually) for every state-changing action on financially/operationally sensitive models, capturing actor, action, target (polymorphic), timestamp, reason (where applicable), `request_id`, and a before/after diff for updates. Sensitive fields (personal ID numbers, full card numbers, salary amounts in low-privilege views) are masked in the diff unless the reader's own permissions allow seeing them unmasked. AuditEvent rows themselves are never editable/deletable by a regular administrator — only exportable under a separately-permissioned, retention-governed export path.
- **Outbox**: `OutboxEvent` row inserted in the same DB transaction as the triggering business write (see DEC-014); a relay process/scheduled job turns unprocessed outbox rows into queued Redis jobs, which are themselves written to be idempotent (checked via `IdempotencyRecord` or a natural idempotency key like `(outbox_event_id)` before applying side effects).
- **Idempotency (API)**: `IdempotencyRecord` keyed by `(organization_id, idempotency_key, endpoint_signature)` storing a request-body hash and the cached response, per DEC-015 / spec section 20.

---

## 6. Suprema device integration shape

- `services/device-connector` is a separate OS process (own runtime, own dependency manifest — exact language/runtime chosen by whichever agent implements it, defaulting to a small PHP or Node service unless the Suprema SDK strongly favors one; documented in that service's own README once built) that speaks the Suprema G-SDK / Device Gateway protocol to devices on the protected LAN/VPN. It never exposes device ports to the public internet, and the browser never talks to it directly (spec section 6).
- It communicates with the main Laravel app only via a versioned, authenticated contract: either (a) an internal `/api/v1/device-connector/*` route group guarded by a Sanctum machine token scoped to that purpose, for commands flowing Laravel → connector and events flowing connector → Laravel, or (b) a Redis queue contract for asynchronous command dispatch — exact final shape is an Integration/Devices-module decision recorded in `docs/decisions.md` when made, but in both cases every inbound connector request is authenticated as a distinct machine identity (never a shared/generic API key) and protected against replay (nonce/timestamp + idempotency key).
- Two implementations exist side by side behind one adapter interface (`DeviceAdapterInterface` in `app/Domain/Devices`): a **Simulator** adapter (in-process or a tiny local fake within `services/device-connector`, always visibly labeled "სატესტო რეჟიმი" / "TEST MODE" in the UI wherever its data is shown) and a **real** Suprema adapter. Nothing may claim real-hardware validation from simulator-only test runs — this is enforced procedurally (Integration/QA sign-off checklist), not just by convention.
- `RawAccessEvent` ingestion is append-only and deduplicated on `(device_id, native_event_id, stream_epoch)`, never on payload hash alone (spec section 6). See `docs/data-model.md` for exact constraint shape.

---

## 7. What Foundation must produce (so later sections of this doc stop being abstract)

The Foundation agent's job (full detail in `docs/implementation-plan.md` § Agent assignment) is to turn every "Foundation-owned" reference above into a real file: the bootstrap route loop, `NavigationService` + nav config contract, `RbacBaseSeeder` + `AggregatingPermissionsSeeder`, the base Policies pattern, the shared Vue design-system components and layouts, the full P0+P1 migration set from `docs/data-model.md`, the Docker Compose stack, and the installable-PWA shell. Every module agent after Foundation extends these; none of them re-create or fork them.
