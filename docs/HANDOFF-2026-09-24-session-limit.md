# HANDOFF — 2026-09-24

State of play after Stage 0 and Stage 1 landed. Written so the next session
can pick up without re-deriving anything.

---

## 1. Committed

| Commit | What |
|---|---|
| `926468a` | Stage 0 — audit A01/A02/A03 (production debug leak, `max(uuid)` 500, `/assets/stocktakes` 404) |
| `79895b0` | Stage 1 — two-person acceptance integrity, spec-03 TM-01…TM-09 |
| `84e0b30` | Audit A12/A13/A15/A16 — searchable pickers, KPI drill-down, duplicated Kanban button |

Gates at `84e0b30`: **359 tests, 355 passed, 4 skipped**, phpstan 0 errors,
pint clean, `vue-tsc` clean, `npm run build` clean. Both new migrations are
applied to `oda_crm`.

### What Stage 1 actually changed

Final acceptance of performed work now takes two different real people. The
`self_close_allowed` carve-out is gone from the workflow (the column survives
as a historical field per §17). Independence is judged on BOTH identities —
User id and Employee id — against a participation snapshot frozen at
submission time, so reassigning a task afterwards cannot retroactively launder
a reviewer who did the work. The same rule is re-checked inside the accepting
transaction, not only in the Policy.

Also in Stage 1: evidence counted in photos by real MIME type (a PDF no longer
satisfies „მინიმუმ N ფოტო"); quantity as a delta against currently available
scope, with partial acceptance leaving the task `in_progress`; §8's acceptance
ledger as the source of truth that `tasks.accepted_quantity` caches; scoped
route bindings so a submission id from another task 404s at the binding; row
locks, `expected_version` and idempotency on the decision path.

### Three real bugs found while verifying that work

1. **The §17 backfill would have failed on every real database.** It writes
   into `task_acceptance_ledger_entries`, which carries FORCE ROW LEVEL
   SECURITY; migrations connect as the ordinary non-superuser role (`oda_app`),
   and FORCE applies RLS to the table owner too. With no `app.current_org_id`
   set — the exact ambient state of `php artisan migrate` — the INSERT is
   refused with `SQLSTATE[42501]`. It passed only because an empty database
   never reaches the INSERT. Confirmed against the real Postgres role, not
   assumed. Fixed by running one organization at a time inside a
   transaction-scoped GUC, and guarded by
   `tests/Feature/Tasks/LedgerBackfillPostgresRlsTest.php`, which uses the
   real database with real historical rows because the SQLite suite
   structurally cannot catch this class of bug.
2. **`TaskPolicy::hasProjectAccess()` took a non-nullable `Project`** while
   every caller passed `$task->project`, which resolves through the tenant
   scope and returns null when the project is not readable. An authorization
   check has to answer that with a denial; it raised a TypeError (500).
3. **`TimesheetPdfTest` called `test()->skip()` inside a test closure**, so on
   any machine without `pdftotext` it ERRORED instead of skipping — the
   opposite of what its own docblock promises.

`A12` and `A13` each also turned out to be a cross-tenant validation hole:
both fields were validated as a well-formed uuid and nothing more, so any
uuid — including another organization's record — was accepted and stored.

---

## 2. Still uncommitted, and deliberately so

A different, long-dormant process owns these and every session has left them
alone. **Do not touch or commit them without asking:**

`app/Domain/Shared/Services/NavigationService.php`, `config/modules/shared-nav.php`,
`docs/architecture.md`, `resources/js/components/AppSidebar.vue`,
`resources/js/components/mobile/{BottomNav,MobileTopBar}.vue`,
`resources/js/lib/mobileNav.ts`.

---

## 3. BioStar 2 — verified live, read-only

Credentials live in `.env` (gitignored) as `BIOSTAR_*`. They are the BioStar
**super-admin** account; spec-02 §7.3 wants a least-privilege read-only service
account before any persistent connector runs. Never put them in code, logs,
exports or a commit.

Verified by login + GET/search reads only, no writes:

- `https://192.168.88.150` reachable. TLS cert is Suprema's own self-signed
  cert with `CN=169.254.28.245`, so hostname verification can never match the
  IP — use `BIOSTAR_CA_CERT_PATH` rather than disabling TLS verification.
- **New Local API is enabled.** `POST /api/login` → 200. The session header on
  this build is confirmed to be **`bs-session-id`** (the spec said verify,
  don't guess — now verified).
- `/api/server/version` → **403 even for admin**. Not a blocker.
- `/api/devices` → **2 devices, both online, XPass 2, firmware 1.5.0**:
  `544452272` "შემოსასვლელი" and `544452273` "აღრიცხვა".
- `/api/doors` → 2 doors, each with one entry device and **`exit_device: null`**.
- `/api/event_types` → 336 types. Access-granted is **`4102 VERIFY_SUCCESS_CARD`**.
- `/api/events/search` works. Over 2026-09-20 → 09-24 there were only **5 card
  events**, all from `544452272`, all from one `user_id`. `/api/users` shows
  **2 enrolled users**. Device `544452273` has produced **zero** card events.

Read-only probe scripts (`biostar_probe.sh`, `biostar_topology.mjs`,
`biostar_events.mjs`) are in the session scratchpad; they read credentials from
`.env` and print no secret values.

### Two blockers that are the user's to resolve

**(a) The device clock is wrong.** `datetime` and `server_datetime` differ by a
consistent ~3h00m05s, and real UTC matched `server_datetime` — so `datetime`
runs ~3h behind true UTC. Georgia is UTC+4, so this matches no sane timezone;
the device clock or its timezone is misconfigured. **Attendance math must not
trust `datetime` until this is fixed on the device.**

**(b) Direction cannot come from hardware.** The user's decision:

> **`544452273` "აღრიცხვა" is THE attendance device — employees badge it on
> arrival AND on departure. `544452272` "შემოსასვლელი" is only courtyard
> access control and is not for attendance.**

So attendance comes from a single reader used for both directions, and both
doors have `exit_device: null`. Per spec-02 §7.6 that means:

- Hardware gives no IN/OUT signal, so exact pairing **cannot be proven**.
- "Alternate every other read as IN/OUT" is **explicitly forbidden**.
- A first-read-of-day / last-read-of-day rule is allowed **only** as an
  explicitly agreed *approximate* policy, labelled as such in the UI. It cannot
  prove breaks, mid-day exits or current presence, and where there is no real
  exit source the UI must say „გასვლა დაუდგენელია" rather than invent hours.
- Worth raising with the user before building: enabling Suprema T&A function
  keys on that reader (if this XPass 2 build supports them) would give real
  direction, and so would adding a second reader as the door's `exit_device`.
  Both are BioStar-side configuration decisions — never change BioStar config
  from the CRM.

Importing events can proceed independently of (b). Accurate session pairing
cannot.

---

## 4. Next, in order

1. **The rest of spec-03 stage 1's acceptance rows.** The suite covers
   DV-03…DV-06, DV-08, EV-01…EV-04, SEC-01, SEC-02, SEC-03, SEC-05, MIG-01,
   MIG-02. Still to write: **DV-01, DV-02, DV-07, SEC-04, and Q-01…Q-07** (the
   §8 quantity-ledger invariants — the delta/cumulative worked example, the
   returned-volume-returns-to-scope case, reversals, and the
   defect-rework-is-not-new-production case).
2. **BioStar read-only connector** (spec-02 §7), once the clock is fixed.
3. Remaining spec-02 audit items: A04, A06–A11, A14, A17–A26, and spec-02
   stages 1–5.

## 5. Standing constraints

- Additive migrations only. Never `migrate:fresh` / `db:wipe` against `oda_crm`.
- PostgreSQL-specific behaviour needs a PostgreSQL test — the SQLite suite hid
  both A02 and the backfill RLS failure above.
- Never grant the app's Postgres role superuser or BYPASSRLS; never disable
  RLS, CSRF, authorization or MFA to make a test pass.
- BioStar is read-only in this phase: no issuing or revoking cards, no creating
  BioStar users, no access-group changes, no opening doors, no direct SQL
  against BioStar's database, no exposing BioStar or RDP to the internet.
- `php artisan serve` on :8182 plus a Cloudflare tunnel is what serves
  `crm.syslab.ge`; the deployed revision IS this working copy. The frontend
  runs from the production build (`public/hot` is intentionally absent)
  because Vite dev-server asset URLs are not reachable through the tunnel.
- The user reads Georgian; UI strings and user-facing reports are Georgian.
