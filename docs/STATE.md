# Current state — 2026-09-25

Written so a session with no memory of the earlier work can pick up from here.
Read `CLAUDE.md` first for how to run the project and what the hard
constraints are.

At the latest commit: **428 tests, 424 passed, 4 skipped**, phpstan 0 errors,
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
DV-02…DV-06, DV-08, EV-01…EV-04, Q-01…Q-07, SEC-01…SEC-05, MIG-01, MIG-02.

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
   - **DV-01** (two audit records for issue/receipt) — the whole Tasks domain
     writes no `AuditEvent` at all. `grep -rn "AuditLogger" app/Domain/Tasks`
     returns nothing. This is the largest real gap.
   - **DV-07** (temporary reviewer substitution) — no delegation entity exists.
   - **EV-05** (task changed while a reviewer was looking at the snapshot).
   - **Q-08** and the work-lot half of **Q-06** — no work-lot or parent/child
     quantity entity exists.
   - **WF-01…06**, **OFF-01…04**, **UI-01…04**, **INT-01/02**.
2. **The BioStar read-only connector** (spec-02 §7). Event import does not
   depend on either device-side blocker above; accurate session pairing does.
   Build the import, and make the unknown-exit case say so.
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
- There is no `AuditEvent` read UI other than the project activity feed
  (`App\Domain\Projects\Services\ProjectActivityFeed`), and `audit_events` has
  no `project_id`, so that service gathers children by id and documents the
  one case it cannot cover.

## 5. History

`docs/agent-handoff.md`, `docs/claude-overnight-progress.md` and
`docs/decisions.md` hold the longer record of earlier passes.
`docs/HANDOFF-2026-09-24-session-limit.md` was the previous state file and is
superseded by this one.
