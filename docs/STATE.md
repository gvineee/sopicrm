# Current state — 2026-09-25

Written so a session with no memory of the earlier work can pick up from here.
Read `CLAUDE.md` first for how to run the project and what the hard
constraints are.

At the latest commit: **444 tests, 440 passed, 4 skipped**, phpstan 0 errors,
pint clean, `vue-tsc` clean, `npm run build` clean, no pending migrations,
working tree clean, everything pushed to `origin/main`.

---

## 1. Waiting on the owner — do not try to fix these in code

**The owner's account still authenticates with the literal password
`password`.** Every account in this database did, because they were all made by
`UserFactory`, which hashes exactly that string, while `APP_ENV=local` made
`DatabaseSeeder`'s "not in production" guard pass on an instance that is served
publicly through a Cloudflare tunnel.

The seven seeded demo accounts have been disabled and that was verified against
the running app. The real account is the owner's to change, at
`/settings/security`. Do not rotate it for them — that locks them out of their
own system.

`php artisan security:check-accounts` reports the current state at any time.

**BioStar 2 is not reachable from this machine.** Port 443 on `192.168.88.150`
does not answer and this host is on `10.10.0.110` — a different network. That,
not the code, is what blocks a live integration test.

Two device-side matters are also the owner's:

1. The device clock runs ~3h behind true UTC while `server_datetime` matches
   it. Importing events does not depend on this; computing worked hours does.
2. „აღრიცხვა" (`544452273`) is the single reader used for both arrival and
   departure, and both doors have `exit_device: null`, so direction cannot come
   from hardware. Per spec-02 §7.6 any pairing is therefore explicitly
   approximate: alternating IN/OUT every other read is forbidden, and where the
   exit is unknown the UI must say „გასვლა დაუდგენელია". Enabling Suprema T&A
   keys on that reader, or adding a second reader as the door's `exit_device`,
   would give real direction — both are BioStar-side configuration, never
   changed from the CRM.

Verified BioStar facts, from read-only probing while it was reachable: the New
Local API is enabled, the session header on this build is **`bs-session-id`**,
there are two XPass 2 devices (`544452272` "შემოსასვლელი", `544452273`
"აღრიცხვა"), access-granted is **`4102 VERIFY_SUCCESS_CARD`**, `/api/events/search`
works, and `/api/server/version` is 403 even for admin. Credentials are in the
gitignored `.env` as `BIOSTAR_*`; they are the super-admin account, and a
least-privilege read-only service account is wanted before any persistent
connector runs.

---

## 2. Finished

**All 26 items of `01-CRM-Audit-KA.md` are closed.** Highlights of what that
actually involved, because several were deeper than the audit described:

| ID | What it turned out to be |
|---|---|
| A01–A03 | Debug pages leaking SQL, stack traces and cookies on a public instance; `max(uuid)` (PostgreSQL has no such aggregate); a wildcard route swallowing `/assets/stocktakes` |
| A04 | Simulator swipes became payroll money — see below |
| A06 | The activity journal's write side had existed all along; nothing read it |
| A11 | No code path anywhere could link an existing account to an employee, so „ჩემი დღე" was permanently empty for anyone who already had a login |
| A14 | A real disclosure: the employee roster listed every company's people to a company-scoped HR user |
| A24 | `projects.clients.manage` had never been created, so no user in any organization could add a client |
| A26 | Every account in the database had the password `password` |

**Spec-03 stage 1 (two-person acceptance) is complete and covered**: TM-01…TM-09,
DV-01…DV-06, DV-08, EV-01…EV-04, Q-01…Q-07, SEC-01…SEC-05, MIG-01, MIG-02,
plus **WF-01** (dependencies enforced before work may begin).

Two modules were added after the audit closed:

- **Task audit trail (DV-01).** The Tasks domain wrote no audit events at all
  — sixteen Actions, not one call to AuditLogger. `TaskAuditRecorder` is now
  the single place they are written, hooked into `TaskStatusEventRecorder` so
  a transition cannot land in the status history and skip the trail. Every row
  carries the task id, project id and the revision it describes. Task history
  reaches the project activity feed. Found while wiring it:
  `ToggleChecklistItem` was dead code — the controller duplicated its body
  inline, bypassing the domain layer entirely.
- **Dependency readiness (§9, WF-01).** Dependencies were recorded and never
  enforced, so work could begin and finish while the work it depends on was
  unaccepted — §9.2's covered-work case. `TaskReadiness` enforces it inside
  StartTask's transaction, counts only `completed` (not `submitted`) as
  satisfied, treats `cancelled` as satisfied so nothing is stranded, and names
  the specific predecessors in the way.

### Seven bugs found that the audit had not listed

1. **The §17 acceptance backfill would have failed on every real database.** It
   writes into an RLS-protected table while migrations connect as the ordinary
   non-superuser role, and `FORCE ROW LEVEL SECURITY` applies to the table owner
   too. It passed only because an empty database never reaches the INSERT.
2. **Simulator swipes became payroll money.** `devices.adapter` defaults to
   `simulator`, the in-app "generate test event" button did not set
   `ingestion_source`, and nothing in the pipeline looked at provenance. Two
   clicks produced a real paid AttendanceSession within five minutes.
3. **The employee roster leaked across companies** while the detail page
   refused to open the same rows.
4. **`projects.clients.manage` never existed** though `ClientPolicy` checked it.
5. **`TaskPolicy::hasProjectAccess()` raised a TypeError** (500) instead of
   denying, when the task's project was not readable in the tenant context.
6. **Three FormRequests validated foreign keys with a bare `exists:`**, which
   runs beneath the tenant scope.
7. **`TimesheetPdfTest` errored instead of skipping** without `pdftotext`.

---

## 3. What to do next

In rough order of value:

1. **Finish spec-03 §16.** Still unwritten, with the reason each one is not
   just an oversight:
   - **DV-07** (temporary reviewer substitution) — no delegation entity exists.
   - **EV-05** (task changed while a reviewer was looking at the snapshot).
   - **Q-08** and the work-lot half of **Q-06** — no work-lot or parent/child
     quantity entity exists.
   - **WF-02** is already satisfied by the cycle checker. **WF-03…06**
     (drawing revisions, cancelling partial work, return history, reviewer
     deadlines), **OFF-01…04**, **UI-01…04**, **INT-01/02** remain.
2. **BioStar session pairing** — see `docs/BIOSTAR-HANDOFF.md`, which is the
   current state of that whole thread. The read path is live and proven against
   the real server (§5 below), and the missing-direction case now says so
   through the `undirected_reader` anomaly. What remains is **not code**: the
   owner has to decide how entry and exit are determined on this install, and
   one badge swipe is needed to confirm the device clock correction. Do not
   pick a direction policy on their behalf.
3. **Spec-02's remaining stages.** That document is the full product spec and
   most of it is still ahead.

---

## 4. Things worth knowing before changing code here

- `resources/js/lib/labels.ts` holds every Georgian label for status codes,
  roles and units, plus the date formatters. Add new enum values there rather
  than inventing a second map.
- `resources/js/components/tasks/TaskKanban.vue` is the one Kanban board, used
  by both the dashboard and the project workspace. The rules that decide which
  drag means "start work" and which means "submit for acceptance" live only
  there — do not copy them into a second screen.
- `App\Domain\Devices\Support\CompanyScope` answers company visibility both for
  a single record (`allows`, used by Policies) and for a query (`apply`,
  `applyThrough`, used by list endpoints). Use the query side on any new list,
  or the list and its detail page will disagree.
- `App\Domain\Tasks\Services\TaskQuantityLedger` is the only source of truth
  for accepted volume; `tasks.accepted_quantity` is a cache of it.
- `App\Domain\Attendance\Models\RawAccessEvent::scopeExcludingSimulated()` must
  be applied by anything that computes worked time, a timesheet or money.
- `App\Domain\Tasks\Services\TaskAuditRecorder` is the only place task audit
  rows are written, and `TaskStatusEventRecorder` calls it — add a new task
  Action and its transition is audited without doing anything.
- `App\Domain\Tasks\Services\TaskReadiness` decides whether work may begin.
  Anything that starts work must go through it.
- There is no `AuditEvent` read UI other than the project activity feed
  (`App\Domain\Projects\Services\ProjectActivityFeed`), and `audit_events` has
  no `project_id`, so that service gathers children by id and documents the
  one case it cannot cover.

## 5. The live BioStar install, as it actually is

Verified against the real server, not assumed:

- Two XPass 2 readers: `544452272` („შემოსასვლელი", the yard gate) and
  `544452273` („აღრიცხვა"). Both doors have `exit_device: NONE`.
- Every real badge read so far is on the gate; the attendance reader has none.
- **The device clock runs about three hours behind.** `server_datetime` is the
  trustworthy UTC and is what `normalized_event_time_utc` stores;
  `raw_device_time` keeps the device's claim beside it so the drift stays
  visible instead of being quietly corrected away.
- **Never filter BioStar's event search by date server-side.** Its only date
  condition matches on `datetime` — the device's own wrong clock — so a window
  request silently drops real events at the boundary. Ask for a bounded number
  of the newest rows and apply the window locally against `server_datetime`.
  `biostar:import-events` does this; measured, it recovered 50 of 451 events
  the server-side filter had been dropping.
- BioStar reports card ids in **decimal**; the CRM's ingest reads **hex**.
  `CardIdentifierNormalizer::fromDecimal()` is the only correct bridge.
- BioStar user id 1 is its own built-in `Administrator` — an operator login,
  not staff. Skipped via `devices.biostar.ignored_user_ids`.
- Nothing in `App\Domain\Devices\Services\BiostarReadClient` writes to BioStar,
  and nothing should be added there that does. The write path belongs in the
  device-connector, behind `biostar_write_dispatch_enabled`.

### A mistake worth not repeating

A check run from `artisan tinker` without setting the RLS GUC reported that the
organization had **zero** employees. It had three; row-level security was
simply hiding them. Acting on that reading created a duplicate employee for a
person who was already on the roster.

Any console or tinker script that reads tenant data must set the GUC first:

```php
DB::statement("select set_config('app.current_org_id', ?, false)", [$orgId]);
```

An empty result from a console context is not evidence that a table is empty.

## 6. History

`docs/agent-handoff.md`, `docs/claude-overnight-progress.md` and
`docs/decisions.md` hold the longer record of earlier passes.
`docs/HANDOFF-2026-09-24-session-limit.md` was the previous state file and is
superseded by this one.
