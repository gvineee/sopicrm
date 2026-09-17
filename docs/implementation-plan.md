# ODA CRM — Implementation Plan / Master Backlog

Source of truth: `ODA_CRM_Claude_Code_Spec.md` v1.2. This is the master backlog referenced by every later agent. Every requirement has a stable ID, a phase (P0–P4 per spec section 22), and a status. **All statuses start at `not-started`.** Later agents update status in place (`not-started` → `in-progress` → `done` / `blocked`) and must not delete or renumber existing IDs — if a requirement turns out not to apply, mark it `not-applicable` with a one-line reason rather than removing it, so the backlog stays an honest audit trail.

Status legend: `not-started`, `in-progress`, `blocked`, `done`, `not-applicable`.

Cross-reference: `docs/architecture.md` (how), `docs/data-model.md` (schema), `docs/decisions.md` (why + pending dependencies + open policy questions), `docs/runbook.md` (tooling/setup).

---

## REQ groups and ID prefixes

| Prefix | Module | Primary spec section |
|---|---|---|
| REQ-FND | Foundation (scaffold, auth/RBAC/tenancy, design system, PWA shell, migrations) | 1–4, 18, 19, 21 |
| REQ-EMP | Employees & Rates | 5 |
| REQ-DEV | Devices, Credentials & Suprema Simulator | 6 |
| REQ-ATT | Attendance, Sessions & Anomalies | 7 (raw event/session/anomaly part) |
| REQ-TSH | Timesheets & Adjustments | 7 (adjustment/approval/lock part) |
| REQ-PAY | Payroll & Payments | 8 |
| REQ-AST | Tools, Assets & Custody | 9 |
| REQ-PRJ | Projects & Work Breakdown | 10 (project/WBS part) |
| REQ-TSK | Tasks, Comments & Attachments | 10 (task workflow part) |
| REQ-JRN | Daily Site Journal (baseline) | 11 |
| REQ-NTF | Notifications & PWA finalization | 17 |
| REQ-MAT | Materials/Warehouse/Procurement/Budget (P2, backlog only) | 12–13 |
| REQ-CRM | CRM/Quality/Documents (P2/P3, backlog only) | 14–16 |
| REQ-EXT | Extended features (P3/P4, backlog only) | 16 |

---

## REQ-FND — Foundation

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-FND-01 | Scaffold Laravel 13 app from the official Vue starter kit (Vue 3 + TS + Inertia + Vite + Tailwind); lock composer/package versions | P0 | not-started |
| REQ-FND-02 | Repo layout per `docs/architecture.md` § 2 (app/Domain, app/Http, app/Policies, app/Jobs, database/migrations, resources/js, routes, tests, docs, infra, services/device-connector skeleton) | P0 | not-started |
| REQ-FND-03 | `routes/api.php`/`routes/web.php` bootstrap-loop pattern + `routes/modules/` directory | P0 | not-started |
| REQ-FND-04 | `organizations` table + global `BelongsToOrganization` Eloquent scope, deriving tenant id only from authenticated session/token | P0 | not-started |
| REQ-FND-05 | Postgres RLS policies on all business tables + dedicated non-superuser runtime DB role (`oda_app`) + `SET LOCAL app.current_org_id` middleware | P0 | not-started |
| REQ-FND-06 | RLS integration test suite connecting as the real restricted runtime role (not superuser) proving cross-tenant isolation | P0 | not-started |
| REQ-FND-07 | `users`, `roles`, `permissions` + spatie/laravel-permission integration; seed roles from spec section 3 (`RbacBaseSeeder`) | P0 | not-started |
| REQ-FND-08 | `AggregatingPermissionsSeeder` glob mechanism + documented module load order | P0 | not-started |
| REQ-FND-09 | Session auth (login/logout, password hashing, CSRF) + rate limiting + login audit + MFA hook for privileged roles | P0 | not-started |
| REQ-FND-10 | Laravel Sanctum setup for machine/API tokens (device-connector identity) | P0 | not-started |
| REQ-FND-11 | Base Policy pattern (Policy checks permission AND project-membership where relevant) + example Policy for a P0 model | P0 | not-started |
| REQ-FND-12 | `NavigationService` + `config/modules/<module>-nav.php` contract + shared nav Vue components (desktop sidebar, mobile bottom-nav, max 5 slots) | P0 | not-started |
| REQ-FND-13 | ODA design system: typography, spacing, color tokens (warm light bg, graphite text/nav, emerald accent, amber warning), radii, elevation, icons, breakpoints, component states, light+dark via shared tokens, WCAG AA target | P0 | not-started |
| REQ-FND-14 | Shared design-system Vue primitives: button, input, table shell w/ server-side sort/filter/pagination, modal, toast, KPI tile, loading/empty/error/permission-denied/offline state components | P0 | not-started |
| REQ-FND-15 | Desktop shell layout: collapsible sidebar, project selector, global search, KPI area, data table + detail drawer pattern, Kanban primitive | P0 | not-started |
| REQ-FND-16 | Mobile shell layout: bottom nav (≤5 items), "ჩემი დღე" composition, task cards, camera action affordance, bottom sheets | P0 | not-started |
| REQ-FND-17 | Full P0+P1 data model migrations per `docs/data-model.md` (Access, Employees, Devices, Attendance, Payroll, Assets, Projects/Tasks, shared entities) | P0 | not-started |
| REQ-FND-18 | `btree_gist` extension + exclusion constraints (rate non-overlap, session non-overlap) | P0 | not-started |
| REQ-FND-19 | Money/UUID/timestamp/version/audit/outbox/idempotency base conventions implemented as reusable traits/casts/base classes | P0 | not-started |
| REQ-FND-20 | Installable PWA shell: Web App Manifest (id, name/short_name "ODA CRM", start_url, scope, standalone, theme/bg colors, 192/512 + maskable icons, apple-touch-icon), service worker (app shell + offline fallback, cache versioning, safe update flow) | P0/P1 | not-started |
| REQ-FND-21 | Android install prompt (`beforeinstallprompt`, feature-detected) + iOS Add-to-Home-Screen guide copy | P1 | not-started |
| REQ-FND-22 | `infra/docker-compose.yml`: app, worker, scheduler, postgres, redis, minio, mailhog, device-connector | P0 | not-started |
| REQ-FND-23 | CI pipeline: lint, typecheck, migrations, Pest tests, frontend build | P0 | not-started |
| REQ-FND-24 | Health/readiness endpoints; worker graceful shutdown | P0 | not-started |
| REQ-FND-25 | Responsive visual baseline validated at 360/390/768/1440px for: employee "my day", project dashboard, task detail w/ photo, tool issue, attendance timesheet | P0 | not-started |

## REQ-EMP — Employees & Rates (spec §5)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-EMP-01 | Employee CRUD form (internal code, name, phone, restricted-visibility personal ID, optional photo, position, skills, team, supervisor, employment dates, status, site assignment periods, emergency contact, permission-gated documents) | P1 | done |
| REQ-EMP-02 | Employee vs. login account separation; HR-initiated invite flow with time-limited one-time link; no shared accounts | P1 | done |
| REQ-EMP-03 | RateHistory CRUD (hourly/daily, amount, currency, effective_from/to, optional project override, reason, approver) with DB-level non-overlap exclusion constraint | P1 | done |
| REQ-EMP-04 | Rate resolution service: project rate → base rate priority; block accrual (not default to zero) when no applicable rate exists | P1 | done |
| REQ-EMP-05 | Employment termination workflow: deactivate login, schedule credential revocation on all relevant devices, surface unreturned tools; historical attendance/financial data untouched; no automatic tool-cost deduction | P1 | done |
| REQ-EMP-06 | Team / TeamMembership management (foreman assignment, single active team per employee) | P1 | done |
| REQ-EMP-07 | EmployeeProjectAssignment periods | P1 | done |
| REQ-EMP-08 | Employees module Policies (HR/owner/system-admin scoped; salary/personal-ID visibility gated by separate permission) | P1 | done |
| REQ-EMP-09 | Pest tests: rate overlap rejection, rate resolution priority, accrual-blocked-when-no-rate, termination side effects | P1 | done |

## REQ-DEV — Devices, Credentials & Suprema Simulator (spec §6)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-DEV-01 | `services/device-connector` process skeleton with versioned, authenticated (Sanctum machine token) contract to Laravel; no public device ports; browser never talks to devices directly | P0 | not-started |
| REQ-DEV-02 | `DeviceAdapterInterface` + Simulator adapter, permanently labeled "სატესტო რეჟიმი" in UI wherever its data appears | P0 | in-progress |
| REQ-DEV-03 | Real Suprema adapter behind the same interface, targeting G-SDK Device/User/Event APIs; capability read from device, never hardcoded | P0/P1 | in-progress |
| REQ-DEV-04 | Device CRUD + status model (online/offline/degraded/unknown) with independent sync_status; capability snapshot storage | P0/P1 | in-progress |
| REQ-DEV-05 | Credential CRUD preserving raw bytes/length/leading zeros; documented decimal/hex/byte-order adapter conversion | P1 | in-progress |
| REQ-DEV-06 | CredentialAssignment with DB-enforced single-active-assignment-per-credential; historical event attribution by assignment validity window, not current pointer | P1 | not-started |
| REQ-DEV-07 | Unmatched/unknown card triage record; never auto-creates an Employee | P1 | not-started |
| REQ-DEV-08 | DeviceSyncCommand queue: pending→processing→succeeded/failed/retry/dead-letter; idempotency key + monotonic command_version so stale retries can't overwrite newer commands | P0/P1 | not-started |
| REQ-DEV-09 | UI: desired state vs. per-device acknowledged state shown separately; offline-device pending revocation never shown as completed | P1 | not-started |
| REQ-DEV-10 | Reconciliation job comparing desired vs actual state; no bulk destructive reset during normal sync | P1 | not-started |
| REQ-DEV-11 | RawAccessEvent immutable ingestion with dedup on (device_id, native_event_id, stream_epoch); payload hash as secondary check only | P0/P1 | not-started |
| REQ-DEV-12 | DeviceCheckpoint-based resume after network outage; overlapping-batch-safe dedup; data-gap flagging on log overflow/lost range | P1 | not-started |
| REQ-DEV-13 | Clock drift / inconsistent ordering detection feeding AttendanceAnomaly | P1 | not-started |
| REQ-DEV-14 | Local-first access decision design note (WAN-independent door decisions) + documented limitation that offline revocation can't propagate instantly | P1 | not-started |
| REQ-DEV-15 | BioStar migration plan doc: read-only inventory/export + card mapping first, single test-reader pilot second, single system-of-record + rollback plan, no dual independent writers | P1 (planning only) | not-started |
| REQ-DEV-16 | P0 pilot acceptance scenario: employee → card assignment → reader confirmation → event received → revocation confirmed, including outage/replay test | P0 | not-started |
| REQ-DEV-17 | Devices module Policies + Pest/integration tests (idempotency, dedup, epoch rollover, revocation-not-shown-as-complete-when-offline) | P0/P1 | in-progress |

## REQ-ATT — Attendance, Sessions & Anomalies (spec §7 raw/session/anomaly part)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-ATT-01 | ShiftTemplate CRUD (site, start/end, night-crossing, scheduled days, break policy, allowed lateness, rounding policy, approver) — all thresholds configurable, none hardcoded | P1 | not-started |
| REQ-ATT-02 | ShiftAssignment CRUD | P1 | not-started |
| REQ-ATT-03 | Deterministic, re-runnable AttendanceSession reconstruction service from RawAccessEvents; exact-minute precision, no auto-rounding of raw time | P1 | not-started |
| REQ-ATT-04 | Break deduction logic: fixed or scheduled break, same break never deducted twice (idempotent per session+break-window) | P1 | not-started |
| REQ-ATT-05 | Overlapping-session DB exclusion constraint + reader-direction determined by configured device role, not naive alternate-read heuristic | P1 | not-started |
| REQ-ATT-06 | AttendanceAnomaly detection: duplicate IN, unknown OUT, missing OUT, excessive duration, impossible site crossing, late-arriving data, clock drift, out-of-order events, data gap | P1 | not-started |
| REQ-ATT-07 | Missing-OUT never auto-pays a full day; session stays open pending resolution | P1 | not-started |
| REQ-ATT-08 | Multi-site-per-day time attribution to correct project | P1 | not-started |
| REQ-ATT-09 | Pest tests matching spec §23 attendance rows: 09:00–18:00 + 60min unpaid break → 480 min; missing OUT → anomaly, no auto full-day pay; duplicate event ×10 → one raw event/one result; reader epoch rollover → new event not lost | P1 | not-started |

## REQ-TSH — Timesheets & Adjustments (spec §7 adjustment/approval/lock part)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-TSH-01 | Manual AttendanceAdjustment form (employee, date, site, corrected in/out or hours, reason, evidence, author, approver); original session never overwritten | P1 | not-started |
| REQ-TSH-02 | Overlap + negative-duration blocking on adjustments | P1 | not-started |
| REQ-TSH-03 | Night-shift attribution to shift start-date; rate-change-boundary splitting into multiple timesheet lines with rate snapshots | P1 | not-started |
| REQ-TSH-04 | Timesheet state machine: draft → submitted → approved → locked; rejected → draft with reason | P1 | not-started |
| REQ-TSH-05 | Approval snapshots source-session versions + calculation policy version used | P1 | not-started |
| REQ-TSH-06 | Locked-period late event handling: creates adjustment request, never silently changes historical pay | P1 | not-started |
| REQ-TSH-07 | Per-day multi-project allocation cannot exceed employee's approved payable minutes for that day | P1 | not-started |
| REQ-TSH-08 | Task closure / task timer explicitly excluded as an independent payroll time source | P1 | not-started |
| REQ-TSH-09 | Approval polymorphic table with target_version staleness check (stale draft can't be treated as approved) | P1 | not-started |
| REQ-TSH-10 | Timesheets module Policies (self-approval forbidden by default; owner-only small-company exception with audit) | P1 | not-started |
| REQ-TSH-11 | Pest tests matching spec §23 timesheet rows: mid-shift rate change → 2×10 + 2×15 = 50.00 GEL with two rate snapshots; locked-period late event → adjustment request, historical pay run unchanged | P1 | not-started |

## REQ-PAY — Payroll & Payments (spec §8)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-PAY-01 | Hourly calculation: approved payable minutes / 60 × effective hourly rate, Decimal only | P1 | not-started |
| REQ-PAY-02 | Daily calculation: approved day units × effective daily rate; full/half-day threshold, minimum attendance, incomplete-day behavior all config-driven, not hardcoded | P1 | not-started |
| REQ-PAY-03 | Default max 1.0 day-unit per employee per work-date across sites; exception requires separate approval | P1 | not-started |
| REQ-PAY-04 | PayRun line stores hours/days, rate snapshot, formula, policy version, project split, adjustments | P1 | not-started |
| REQ-PAY-05 | Half-up rounding to 0.01 GEL at final line; deterministic remainder allocation across project splits (sum preserved exactly) | P1 | not-started |
| REQ-PAY-06 | Configurable PayAdjustment categories (overtime, holiday, bonus, vacation, absence); statutory coefficients/taxes NOT activated without confirmed accountant-approved policy | P1 | not-started |
| REQ-PAY-07 | PayRun state machine: draft → calculated → reviewed → approved → locked | P1 | not-started |
| REQ-PAY-08 | Payment record (date, amount, currency, method, evidence, reference) separate from approval; partial payments allowed; `pending` never counted as `paid` | P1 | not-started |
| REQ-PAY-09 | Outstanding balance formula: approved amount − allocated payments − allocated advances; advance deducted exactly once | P1 | not-started |
| REQ-PAY-10 | Deduction for damaged tools/fines/debt requires separate permission, reason, and approved company rule — never automatic | P1 | not-started |
| REQ-PAY-11 | Locked-period correction via reversal/adjustment row, never delete/edit | P1 | not-started |
| REQ-PAY-12 | Payroll CSV export (period, employee, hours, days, accrual, advance, paid, balance) hardened against spreadsheet formula injection | P1 | not-started |
| REQ-PAY-13 | CSV export UI copy makes clear it is not a real bank transfer | P1 | not-started |
| REQ-PAY-14 | Payroll module Policies (Finance role; system admin has no automatic financial access; PM cannot pull payroll export without explicit permission) | P1 | not-started |
| REQ-PAY-15 | Pest tests matching spec §23 payroll rows: 480 min × 15 GEL/hr = 120.00 GEL; 1 approved day × 100 GEL = 100.00 GEL; salary 1000, advance 200, payment 300 → balance 500, advance not double-deducted; PM without permission requesting payroll export/API → denied | P1 | not-started |

## REQ-AST — Tools, Assets & Custody (spec §9)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-AST-01 | Asset registration form (individual/kit/quantity/consumable distinction, unique inventory code, initial location, condition, optional brand/model/serial/purchase/warranty/photos/manual/bundle/ownership/calibration-service due) | P1 | not-started |
| REQ-AST-02 | QR code generation (opaque token) resolving to a Policy-checked asset page; QR possession alone grants no access | P1 | not-started |
| REQ-AST-03 | Issue form: asset/kit or quantity line items, issuing warehouse, receiving employee, project/site, issue time, expected return, condition, accessories, photo, comment, dual confirmation; draft-savable; final issue requires real available balance + permission | P1 | not-started |
| REQ-AST-04 | Issue status machine draft → awaiting_receipt → issued → partially_returned/returned; "issued, receipt pending" never shown as available stock | P1 | not-started |
| REQ-AST-05 | Return form: reference issue, returned items/qty, receiving warehouse, condition, lost accessories, photos, comment, inspector; damaged → quarantine/repair, not available; partial return preserves remaining obligation | P1 | not-started |
| REQ-AST-06 | Transfer form: source/destination, site, assets, date, condition, both-party confirmation, in_transit intermediate state; direct employee-to-employee keeps full custody chain | P1 | not-started |
| REQ-AST-07 | Damage/loss/service/write-off form; service record tracks vendor/due/actual cost/next service; write-off requires authorized approval, history retained | P1 | not-started |
| REQ-AST-08 | Stocktake session: expected snapshot, QR scan, found/short/excess, recount, approved adjustment; scan never directly mutates ledger balance | P1 | not-started |
| REQ-AST-09 | Concurrency-safe single-issue guarantee (transaction + lock/unique constraint) for simultaneous double-issue attempts | P1 | not-started |
| REQ-AST-10 | Reports: who-holds-what, overdue returns, per-project allocation, service history, lost assets, full asset history | P1 | not-started |
| REQ-AST-11 | Employee self-service: confirm receipt, request return, report damage | P1 | not-started |
| REQ-AST-12 | Assets module Policies (warehouse keeper scope; salary/other-warehouse access separate) | P1 | not-started |
| REQ-AST-13 | Pest tests matching spec §23 asset rows: two simultaneous issue attempts on same asset → only one transaction succeeds; kit partially returned → correct remaining obligation and item condition | P1 | not-started |

## REQ-PRJ — Projects & Work Breakdown (spec §10 project/WBS part)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-PRJ-01 | Project CRUD (code, name, client, manager, address, dates, status, members, budget baseline, documents) | P1 | not-started |
| REQ-PRJ-02 | Optional-depth WBS: Project → corpus/zone → floor → space → work package → task; small projects need not populate every level | P1 | not-started |
| REQ-PRJ-03 | Project membership management feeding Policy checks (manager sees own projects/resources only) | P1 | not-started |
| REQ-PRJ-04 | Projects module Policies + Pest tests (PM cannot see other projects or other employees' personal rates) | P1 | not-started |

## REQ-TSK — Tasks, Comments & Attachments (spec §10 task workflow part)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-TSK-01 | Task form (title, description, project/location, single accountable owner, additional assignees/team, priority, due date, planned duration, checklist, required tools/materials, dependencies, drawing+revision link, completion requirements, unit/planned/accepted quantity with configurable units) | P1 | not-started |
| REQ-TSK-02 | Task status machine draft→assigned→in_progress→blocked→submitted→completed, with reviewer-return-to-in_progress-with-comment, cancel/reopen requiring reason, full history retained | P1 | not-started |
| REQ-TSK-03 | Employee "mark done" flow: required photos/comment → submit for acceptance; manager normally closes; pre-enabled self-close for low-risk tasks visible on task + audit; server blocks closing someone else's task | P1 | not-started |
| REQ-TSK-04 | TaskDependency with cycle prevention | P1 | not-started |
| REQ-TSK-05 | Comments: text, mentions, replies, author/time, edit history | P1 | not-started |
| REQ-TSK-06 | Attachments: photo/PDF upload from mobile camera, multi-photo, progress/retry, caption, before/after classification; upload time is the trusted timestamp, EXIF is unverified metadata; optional/transparent GPS, no hidden tracking | P1 | not-started |
| REQ-TSK-07 | Completion rules: required checklist, minimum evidence by task type, accepted ≤ submitted quantity, re-acceptance doesn't double-count volume, failed required-photo upload keeps submission recoverable in draft (never silently "submitted") | P1 | not-started |
| REQ-TSK-08 | Views: my day, list, Kanban, calendar (Gantt/dependency scheduling explicitly P2, not built now); filters by project/team/owner/due/status/zone | P1 | not-started |
| REQ-TSK-09 | Blocked task requires reason + owner of unblocking | P1 | not-started |
| REQ-TSK-10 | Project progress computed from accepted quantities/weights per unit type; never sums incompatible units or treats raw task count as real progress | P1 | not-started |
| REQ-TSK-11 | Tasks module Policies + Pest tests matching spec §23: task closure without required photo → validation error, draft preserved; offline comment/photo resubmitted → single submission, no duplicate; employee opening someone else's task/file URL → denied server-side | P1 | not-started |

## REQ-JRN — Daily Site Journal, baseline (spec §11)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-JRN-01 | Daily report form (project/date, responsible person, teams, attendance-derived headcount + manual override/variance note, work performed, equipment used, materials received, delays, quality/safety notes, photos, next-day plan, manual weather) | P1 | not-started |
| REQ-JRN-02 | Fill → submit → manager-accept workflow | P1 | not-started |
| REQ-JRN-03 | Closed-day edit creates a revision, not an overwrite | P1 | not-started |
| REQ-JRN-04 | Journal work quantity links to task accepted_quantity by reference; no duplicate financial posting | P1 | not-started |
| REQ-JRN-05 | Daily Journal module Policies + Pest tests | P1 | not-started |

## REQ-NTF — Notifications & PWA finalization (spec §17)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-NTF-01 | In-app notifications: task assigned, tool return due, mention, overdue, tool deadline, timesheet exception, device fault | P1 | not-started |
| REQ-NTF-02 | Notification dedup, read/unread, deep link, per-user preferences | P1 | not-started |
| REQ-NTF-03 | Safe preview formatting: never show full salary or full card number in lock-screen/preview text | P1 | not-started |
| REQ-NTF-04 | Mobile offline: pre-cached own tasks (bounded), comment/photo draft + queued submission, distinct "saved locally" vs "sent to server" states | P1 | not-started |
| REQ-NTF-05 | Photo blob local retention when space allows; quota handling with honest (non-false-positive) save confirmation | P1 | not-started |
| REQ-NTF-06 | Idempotent replay on reconnect; parent-task version-conflict handling; revoked-permission or closed-task offline submissions go to triage, not silent accept | P1 | not-started |
| REQ-NTF-07 | Financial approval, final tool issue, and credential changes explicitly blocked in offline mode | P1 | not-started |
| REQ-NTF-08 | Logout clears protected cache; salary/personal-ID never cached offline by default | P1 | not-started |
| REQ-NTF-09 | IndexedDB-bounded offline queue; resync on foreground/app-open/network-restore; background sync is optional enhancement only, not relied on for iOS closed-app upload | P1 | not-started |
| REQ-NTF-10 | No shared cache of authorized responses; logout/user-switch clears protected local data | P1 | not-started |
| REQ-NTF-11 | Manual acceptance test pass on real Android Chrome + real iPhone Safari (install, standalone launch, login/logout, camera upload, restart draft recovery, foreground sync/dedup, safe SW update) with OS/browser versions recorded — simulator/emulation alone does not satisfy this | P1 | not-started |
| REQ-NTF-12 | Notifications/offline module Policies + Pest/E2E tests (duplicate offline submission → single result) | P1 | not-started |

## REQ-MAT — Materials, Warehouses, Procurement, Budget (P2 — backlog only, not built now)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-MAT-01 | Material catalog (SKU, unit, category, min stock, batch/expiry) with explicit unit-conversion ratios | P2 | not-started |
| REQ-MAT-02 | Central/site/transit warehouses; append-only stock ledger; balance derived from ledger; posted ops corrected via reversal; negative stock forbidden by default | P2 | not-started |
| REQ-MAT-03 | Procurement cycle: request → limit approval → quotation comparison → supplier selection → PO → partial/full receipt → invoice match → payment request; PO/receipt/invoice 3-way match with variance review | P2 | not-started |
| REQ-MAT-04 | Purchase request form with configurable approval thresholds; split-to-bypass-limit detection/reporting | P2 | not-started |
| REQ-MAT-05 | Moving weighted average costing (P2 starting method, to be confirmed with finance) with documented reversal rules | P2 | not-started |
| REQ-MAT-06 | BOQ (work package, cost code, unit, qty, unit prices, total, revision); approved baseline immutable, changes via version/change order | P2 | not-started |
| REQ-MAT-07 | Budget views: baseline, approved changes, current budget, actual, remaining commitment, forecast-to-complete/at-completion; commitment→actual transition not double-counted; forecast formula/assumptions visible in UI | P2 | not-started |
| REQ-MAT-08 | Cost sources posted exactly once per source document line; employee payment doesn't create a duplicate labor cost entry | P2 | not-started |
| REQ-MAT-09 | Client change-request workflow: request → estimate → approval → budget/schedule revision; unapproved changes shown separately in forecast | P2 | not-started |
| REQ-MAT-10 | Registers: expense requests, invoices, receivables/payables, partial payments, overdue, cash-flow plan; FX snapshot per transaction, no cross-currency summation without conversion | P2 | not-started |

## REQ-CRM — CRM, Quality, Safety, Documents (P2/P3 — backlog only, not built now)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-CRM-01 | CRM pipeline (lead→qualified→estimate→proposal→negotiation→won/lost) with lost-reason; won-lead doesn't duplicate client/contract on project creation | P3 | not-started |
| REQ-CRM-02 | Contract management (parties, number, amount/currency, terms, milestone schedule, attachments, retention/warranty, version, signature status); in-app approval ≠ legal e-signature | P3 | not-started |
| REQ-CRM-03 | Client/subcontractor portal: shared progress/accepted work/selected photos/documents/change requests only; internal comments and cost hidden; sharing explicit and revocable | P3 | not-started |
| REQ-CRM-04 | Optional real-estate sales module (unit/apartment, area, price, availability, reservation, contract, installment schedule); double-sale/double-reservation blocked transactionally; kept out of core contractor P1 | P3/P4 | not-started |
| REQ-CRM-05 | Quality: inspection templates, checklist pass/fail/NA, defects/punch list with severity, corrective tasks, re-inspection; high-risk/covered-work acceptance can gate next stage | P2 | not-started |
| REQ-CRM-06 | Safety: induction, PPE issue, certificate expiry, work-permit register, incident/near-miss, corrective actions; access auto-restriction on expiry only via explicit company policy + verified hardware rule; emergency egress never blocked by CRM | P2/P3 | not-started |
| REQ-CRM-07 | Documents: drawings with revisions, contracts, acts, instructions; metadata/project/category/version/author/approval; task binds to a specific drawing revision; markup stored as separate layer | P2 | not-started |
| REQ-CRM-08 | RFI workflow (question→responsible→due date→answer→close) | P2/P3 | not-started |
| REQ-CRM-09 | Submittal workflow (submitted material/sample/doc → review → approved/revise/rejected) | P2/P3 | not-started |
| REQ-CRM-10 | Handover: acceptance checklist, defect closure, as-built docs, warranty periods | P2/P3 | not-started |

## REQ-EXT — Extended features (P3/P4 — backlog only, not built now)

| ID | Requirement | Phase | Status |
|---|---|---|---|
| REQ-EXT-01 | Subcontractors: contract, team, agreed rates, completed-volume act, acceptance, payment schedule; no duplicate posting between own payroll and subcontractor act | P3 | not-started |
| REQ-EXT-02 | Heavy equipment/vehicles: ownership/rental, operator, site, engine hours, fuel, service, bookings, downtime, usage cost; overlapping calendar bookings blocked | P3/P4 | not-started |
| REQ-EXT-03 | Resource planning: brigade load, skill matching, site reallocation, tool availability, Gantt dependencies, delay impact; planned time never becomes attendance | P3/P4 | not-started |
| REQ-EXT-04 | Optional AI assistance (daily report summary, cost anomaly explanation, invoice field extraction draft, similar-defect search, delay risk) — every suggestion cites its source and requires human approval; AI never mutates payroll/stock/access/document status on its own; external model use requires explicit privacy policy opt-in; uploaded documents treated as data, never as instructions to the AI | P3/P4 | not-started |
| REQ-EXT-05 | Multi-company onboarding; billing (only after a SaaS sales decision); accounting/banking integrations; advanced analytics | P4 | not-started |

---

## Agent assignment — fixed execution order

Each ticket below is a distinct agent run. **This order is mandatory**: every module agent after Foundation depends on Foundation's scaffold, migrations, and shared conventions; Integration depends on every module agent having finished; QA depends on Integration. An agent must re-read `docs/architecture.md` § 3 (Module Contribution Convention) and its own REQ rows in this file before writing any code, and must update this file's Status column for every REQ it touches before finishing.

1. **Foundation** — REQ-FND-*. Scaffold, auth/RBAC/tenancy, design system + installable PWA shell, full P0+P1 data model/migrations. Owns: `routes/api.php`/`web.php` bootstrap, `NavigationService`, `RbacBaseSeeder`/`AggregatingPermissionsSeeder`, shared Vue layouts/components, `infra/docker-compose.yml`, CI config. Must re-check PHP/Composer/Node/npm/Postgres availability first and follow `docs/runbook.md` if anything is still missing.
2. **Module: Employees & Rates** — REQ-EMP-*. Spec section 5.
3. **Module: Devices, Credentials & Suprema Simulator** — REQ-DEV-*. Spec section 6.
4. **Module: Attendance, Sessions & Anomalies** — REQ-ATT-*. Spec section 7 (raw event/session/anomaly part). Depends on REQ-DEV (RawAccessEvent ingestion) and REQ-EMP (RateHistory not required yet, but Employee/Team required).
5. **Module: Timesheets & Adjustments** — REQ-TSH-*. Spec section 7 (adjustment/approval/lock part). Depends on REQ-ATT.
6. **Module: Payroll & Payments** — REQ-PAY-*. Spec section 8. Depends on REQ-TSH (approved timesheets) and REQ-EMP (rates).
7. **Module: Tools, Assets & Custody** — REQ-AST-*. Spec section 9. Depends on REQ-EMP (employees as custodians) and REQ-PRJ (project/site scoping) — see ordering note below.
8. **Module: Projects & Work Breakdown** — REQ-PRJ-*. Spec section 10 (project/WBS part).
9. **Module: Tasks, Comments & Attachments** — REQ-TSK-*. Spec section 10 (task workflow part). Depends on REQ-PRJ.
10. **Module: Daily Site Journal baseline** — REQ-JRN-*. Spec section 11. Depends on REQ-PRJ, REQ-ATT (headcount), REQ-TSK (accepted quantity reference).
11. **Module: Notifications & PWA finalization** — REQ-NTF-*. Spec section 17. Depends on essentially every other module existing (it notifies on their events) — deliberately last among module agents.
12. **Integration** — wiring, dependency install (consumes `docs/decisions.md` § Pending dependencies), running all outstanding migrations in order, full test-suite run (`php artisan test`, frontend typecheck, `npm run build`, Playwright E2E), fixing cross-module breakage.
13. **Functional QA Manager** — full functional description, acceptance-table verification against spec section 23 and the P0/P1 acceptance scenarios in section 22, gap-filling, completion report per spec section 25 (what works; how to run it; what tests ran and with what result; which integration is really hardware-verified vs. simulator-only; what remains; which business policy still needs sign-off).

**Ordering note on step 7 vs. 8**: the spec lists Assets before Projects in section numbering (9 before 10), but Assets' "issue to project/site" field only needs `projects`/`sites` to exist as FK targets, not full Project module functionality — Foundation's migrations already create the `projects`/`sites` tables (REQ-FND-17), so the Assets agent can run before the Projects *feature* agent without being blocked. If the Assets agent finds it needs a Projects-module UI affordance that doesn't exist yet, it stubs against the raw FK and leaves a note for the Integration agent rather than reordering itself.

---

## Confirmed vs. unconfirmed parameters (spec section 2, kept explicit per section 25 instruction)

**Confirmed (build against these):** Laravel 13 + premium desktop/mobile UI; fully responsive web + installable Android/iOS PWA; full construction-company CRM; employees + hourly/daily pay as first priority; existing hardware is Suprema XPass 2 XP2-MDPB (EM + MIFARE, mullion-mount); card/attendance management must live in ODA; tool/asset tracking forms required; PMs assign tasks; employees add comments/photos and complete tasks.

**Assumed (stated as working assumptions in the spec, followed unless the user overrides):** Georgian UI; GEL base currency; `Asia/Tbilisi` default company timezone; multiple sites/warehouses; employees use phone browser/PWA; single-company launch with `organization_id` on every business record for future multi-tenant growth.

**Still unknown — tracked in `docs/decisions.md` § Open business-policy questions, must not be guessed:** employee/device counts at real sites; physical in/out reader topology; firmware versions; existing BioStar status; hosting target; night-shift/break/daily-rate rounding rules; these block only the corresponding feature's *production* activation, never block building against the simulator/config-driven defaults.
