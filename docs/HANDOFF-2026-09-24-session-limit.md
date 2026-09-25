> **Superseded by `docs/STATE.md`.** Kept for the detail it records about
> the 2026-09-24/25 pass; start from STATE.md instead.

# HANDOFF — state as of 2026-09-25

Everything below is committed and pushed to `origin/main` unless it says
otherwise. Gates at the latest commit: **428 tests, 424 passed, 4 skipped**, phpstan 0
errors, pint clean, `vue-tsc` clean, `npm run build` clean, no pending
migrations, working tree clean.

**All 26 items of 01-CRM-Audit-KA.md are closed.**

---

## 1. URGENT — for the owner, not for the next session

**Every account in the running database authenticates with the literal
password `password`**, including the owner's own `admin@protect.ge`. All eight
hold the `owner` role; seven are seeded demo accounts on `@example.com`.

They were all created by `UserFactory`, which hashes exactly that string.
`APP_ENV` is `local`, so `DatabaseSeeder`'s "not in production" guard was
satisfied on paper while the same instance is served to the internet through a
Cloudflare tunnel as `crm.syslab.ge`.

Nothing was changed: rotating someone's password without telling them locks
them out of their own system, and deciding which accounts are real is the
owner's call. Run `php artisan security:check-accounts` to see the current
state. The remaining steps are the owner's:

1. Change the real account's password at `/settings/security`.
2. Disable or delete the seven demo accounts.
3. Consider closing the tunnel until step 1 is done.

The seven demo accounts have since been disabled at the owner's request
(`php artisan security:disable-demo-accounts --apply`), and that was verified
against the running application: signing in as `test@example.com` with its
correct password is now refused. **The real account's password is still
`password`** — only the owner can change that.

`DatabaseSeeder` no longer trusts `APP_ENV` alone — it now refuses to create
demo accounts when `APP_URL` is not a local host.

---

## 2. Audit items — what is done

| ID | What it was | Outcome |
|---|---|---|
| A01–A03 | Debug leak on a public instance, `max(uuid)` 500, `/assets/stocktakes` 404 | Fixed, with regression tests |
| A04 | Simulator swipes became paid time | Fixed — see below |
| A05 | "Phone empty in edit" | Not reproducible; round-trip test added |
| A06 | Activity tab said the journal was not implemented | Built; write side already existed and nothing read it |
| A07 | "List and Kanban" link led to a list only | Board shared with the dashboard, view switch added |
| A08 | Edit form could not manage crew / dependencies / checklist | At parity with create |
| A09 | Account list and employee list shared nothing | Each account now names its employee |
| A10 | Assignment period could not be corrected or ended | Both, audited |
| A11 | „ჩემი დღე"/„ჩემი პროფილი" dead end | Existing accounts can be linked to employees |
| A12/A13 | Hand-typed UUID fields | Searchable pickers — and two cross-tenant holes closed |
| A14 | Company visibility unverified | Real leak found in the employee roster, fixed |
| A15/A16 | KPI cards led nowhere; duplicated Kanban button | Both fixed |
| A18/A20/A21/A23/A25 | Mixed language, free-text roles, unreadable access screen | One Georgian label module; see §4 for what remains |
| A19 | `0.00 / — 20` | Unit and quantity only accepted together |
| A22 | Title vs. role confusion | The three concepts are now stated plainly |
| A24 | Empty client dropdown | The permission did not exist; client management built |
| A26 | Test data separation | See §1 |

### Bugs found that the audit had not listed

1. **The §17 acceptance backfill would have failed on every real database.**
   It writes into an RLS-protected table while migrations connect as the
   ordinary non-superuser role, and `FORCE ROW LEVEL SECURITY` applies to the
   owner too. It passed only because an empty database never reaches the
   INSERT. Verified against the real Postgres role.
2. **Simulator swipes became payroll money.** `devices.adapter` defaults to
   `simulator`, the in-app "generate test event" button did not set
   `ingestion_source`, and nothing in the pipeline ever looked at provenance.
   Two clicks produced a real paid session within five minutes.
3. **The employee roster listed every company's people** to a company-scoped
   HR user, while the detail page refused to open any of them.
4. **`projects.clients.manage` never existed**, so no user in any organization
   could create a client — the dropdown was unfillable, not merely empty.
5. **`TaskPolicy::hasProjectAccess()` raised a TypeError** (500) instead of
   denying, when the task's project was not readable in the tenant context.
6. **Three FormRequests validated foreign keys with a bare `exists:`**, which
   runs beneath the tenant scope, so another organization's id passed.
7. **`TimesheetPdfTest` errored instead of skipping** on any machine without
   `pdftotext`.

---

## 3. Spec-03 (construction task manager)

Stage 1 is complete and covered: TM-01…TM-09, DV-02…DV-06, DV-08, EV-01…EV-04,
Q-01…Q-07, SEC-01…SEC-05, MIG-01, MIG-02.

Still unwritten from §16, and why: **DV-01** (paired audit records — the Tasks
domain writes no audit events at all yet), **DV-07** (temporary reviewer
substitution — no delegation entity exists), **EV-05**, **Q-08** and the
work-lot half of **Q-06** (no work-lot or parent/child quantity entity),
**WF-01…06**, **OFF-01…04**, **UI-01…04**, **INT-01/02**.

---

## 4. The navigation files, now taken over

A17 (menu hierarchy) and half of A25 (mobile „პროფილი") lived in navigation
files another process had left uncommitted since 2026-09-21. The owner asked
for that work to be taken over rather than reverted, so it was reviewed, kept,
finished and committed. The working tree is now clean.

What that work does: the sidebar is ordered by daily work rather than by the
order module config files are globbed in (and a group nobody named in that
order still appears, at the end, so ordering never becomes a filter); a
„ჩემი სამუშაო" group gathers the screens a worker actually opens; and the
mobile bottom bar is built from the user's own server-filtered navigation
instead of five fixed slots.

Finished on top of it: the bottom bar's person-shaped tab is named „ანგარიში",
because it opens the ACCOUNT settings screen, while the menu's „ჩემი პროფილი"
opens the WORK profile. Two different pages behind two names that read the
same is how a person concludes one of them is broken.

## 5. BioStar 2 — verified, then blocked

Credentials are in `.env` (gitignored) as `BIOSTAR_*`. They are the BioStar
super-admin account; a least-privilege read-only service account is wanted
before any persistent connector runs. Never put them in code, logs, exports or
a commit.

Verified earlier by login + GET reads only: the New Local API is enabled, the
session header on this build is **`bs-session-id`**, there are two XPass 2
devices (`544452272` "შემოსასვლელი", `544452273` "აღრიცხვა"), both doors have
`exit_device: null`, access-granted is **`4102 VERIFY_SUCCESS_CARD`**, and
`/api/events/search` works.

**As of 2026-09-25 the server is not reachable from this machine.** Port 443 on
`192.168.88.150` does not answer and this host is on `10.10.0.110` — a
different network. That is the only thing blocking a live test today.

Two device-side matters remain the owner's:

1. **The device clock is wrong** — `datetime` runs ~3h behind true UTC while
   `server_datetime` matches it. Importing events does not depend on this;
   computing worked hours does.
2. **Direction cannot come from hardware.** „აღრიცხვა" is the single reader
   used for both arrival and departure. Per spec-02 §7.6 that makes pairing
   explicitly approximate: alternating IN/OUT every other read is forbidden,
   and where the exit is unknown the UI must say „გასვლა დაუდგენელია" rather
   than invent hours. Enabling Suprema T&A keys on that reader, or adding a
   second reader as the door's `exit_device`, would give real direction — both
   are BioStar-side configuration, never changed from the CRM.

---

## 6. Standing constraints

- Additive migrations only. Never `migrate:fresh` / `db:wipe` against `oda_crm`.
- PostgreSQL-specific behaviour needs a PostgreSQL test. The SQLite suite hid
  A02 and the backfill RLS failure; both now have real-Postgres tests.
- Never grant the app's Postgres role superuser or BYPASSRLS; never disable
  RLS, CSRF, authorization or MFA to make a test pass.
- BioStar stays read-only in this phase: no issuing or revoking cards, no
  creating BioStar users, no access-group changes, no opening doors, no direct
  SQL against its database, no exposing BioStar or RDP to the internet.
- `php artisan serve` on :8182 plus a Cloudflare tunnel serves `crm.syslab.ge`;
  the deployed revision IS this working copy, and the frontend runs from the
  production build (`public/hot` is intentionally absent).
- The user reads Georgian; UI strings and user-facing reports are Georgian.
