# Claude Code — overnight execution goal

Prepared: 2026-09-21. This is an implementation assignment, not a claim that its tasks are already complete.

## User-authorized goal

Continue implementing and verifying the existing ODA CRM throughout this work session. Turn the audited defects and requirements into tested, reviewable changes. Prioritize tenant-safe operation, correct permissions, reliable attendance, a simple Georgian worker experience, and single/bulk timesheet emailing. After completing one bounded task, proceed to the next eligible task without waiting for routine confirmation. Do not stop at a plan, the first successful fix, or an intermediate progress report.

This is a large backlog, not a promise that the entire platform can be finished in one night. Maximize verified progress; do not trade security, data preservation or truthful reporting for apparent completion. The user can interrupt or redirect this assignment at any time.

Claude owns both backend and frontend changes required by these tickets for this session. Older frontend-only ownership notes are superseded for this assignment, but another agent's actively edited files and all existing changes must still be respected.

## Read before acting

1. Applicable repository `AGENTS.md`, `CLAUDE.md` and local development instructions, if present.
2. `docs/agent-handoff.md` — read the dated 2026-09-21 entries first; older progress notes may be stale.
3. `docs/platform-audit-2026-09-21.md` — evidence and limitations.
4. `docs/claude-platform-completion-2026-09-21.md` — detailed tickets and acceptance criteria; this is the implementation specification.
5. `docs/claude-overnight-progress.md` — persistent execution checkpoint.

Inspect `git status --short`, relevant diffs, package scripts, test configuration and the actual code. Reconcile already completed work with the tickets before changing it. Do not redo a task solely because an old document calls it incomplete.

The prior audit recorded 142 PHP tests passed, 2 skipped, 686 assertions; 13 connector mock tests passed; typecheck, Pint and build passed; PHPStan reported 9 errors. These are historical baselines, not proof of the current worktree. The audit did not complete a live browser or hardware acceptance run.

## Non-negotiable architecture

- Preserve Laravel/PostgreSQL as CRM business-data authority. Reuse existing Company, Employee, Site, Project and Timesheet concepts; do not build parallel models or separate mobile business logic.
- The user's latest direction is to retain BioStar as device/card/physical-access manager. CRM imports events and handles attendance, timesheets, payroll and reports. Enforce read-only integration for this phase. Earlier plans to replace BioStar are not the current overnight target.
- Do not send enrollment, card assignment, access-policy changes, deletes or door-opening commands to real devices/BioStar during this session. CRM termination must not claim hardware revocation without a separately confirmed BioStar action.
- Do not assume a BioStar API is free, available or supported for a particular installation. Verify legitimate integration options before live use. No license bypass, undocumented direct database writes or fabricated capability claims. Build mockable contracts and fixtures when external integration is unavailable.
- Full administration is a durable role/permission design, not an email-string bypass. Preserve the intended administrator access for `admin@protect.ge` while enforcing explicit tenant selection, financial safeguards and auditing. Do not make everyone admin to eliminate 403 responses.
- Georgian, simple, touch-friendly UI. Worker default flow: my tasks → open → start → photo/comment → submit → manager accepts/returns. Only authorized functionality and fields should appear, with matching server-side protection.

## Execution loop — repeat until the session ends or work is genuinely exhausted

1. Select the highest-priority eligible ticket below. Read its complete acceptance criteria and affected code. Identify dependencies and existing implementation.
2. Update the progress file: active ticket, intended files, reproduction/evidence and concrete next step. Check for overlapping edits.
3. For a defect, add a failing regression test where practical; for new functionality, define the positive, forbidden and failure-path tests first.
4. Implement a small vertical slice: domain/data → authorization → endpoint → UI → asynchronous behavior, where applicable. Preserve working public contracts, or update every caller and document the change.
5. Run focused tests and relevant checks. Inspect failures; fix regressions before dependent work. A green mock test does not prove live integration.
6. Exercise the relevant desktop/mobile UI if a browser surface is available. If it is not, continue automated and code work and record `browser verification pending`; never invent screenshots or acceptance results.
7. Update progress and handoff with exact files, commands, results, limitations and next action. Then immediately select the next eligible ticket. A checkpoint is not a request for permission to continue.
8. After coherent batches, run the full available gates. Keep changes small enough to review and diagnose.

If the same check fails repeatedly, change the diagnostic approach; do not blindly rerun it. After three attempts without new evidence, record the blocker and move to an independent ticket. A failed critical invariant blocks its dependent feature, not unrelated useful work. Never delete a test, weaken an assertion or add an ignore merely to obtain green output.

## Ordered work queue

Use the detailed ticket document for implementation details. The wave order is a dependency guide, not permission to skip acceptance criteria.

### Wave 1 — correctness and security foundation

1. **FIX-01:** resolve the PHPStan findings, including the actual `Zone::devices()` return type. Test the relationship, not just its annotation.
2. **FIX-02:** consistent project/task visibility across list, detail, dashboards and direct endpoints. Test assigned worker, manager, owner, unrelated user and another tenant.
3. **ADMIN-01:** replace email-based unconditional Gate access with explicit administration, tenant context and protected sensitive-action requirements. Do not silently change the real administrator's password.
4. **TENANT-01:** verify company/site ownership and request/job PostgreSQL tenant context before validation and persistence. Cover company/project create and duplicate-code validation, RLS enforcement and cleanup between requests/jobs. Preserve existing records with additive migrations/backfills.
5. **MONEY-01:** financial idempotency, concurrent payments, currency consistency and advance ownership. Fix backend invariants before adding presentation features around them.
6. **ATT-01:** granted/denied filtering, employee identity at event time, historical card reassignment, overnight shifts and recalculation boundaries; never alter locked payroll silently.

Wave exit evidence: regression tests for the defects addressed, no introduced cross-tenant access, no disabled RLS or email-based blanket bypass, and clear recording of any outstanding PostgreSQL verification.

### Wave 2 — trustworthy event ingestion and processing

7. **BIO-01:** enforce read-only behavior through server and adapter boundaries; UI must not report a card granted based on a user-only command.
8. **BIO-02:** source/tenant-aware external employee/card mappings and historical assignment validity. Unknown identities stay explicit and reviewable.
9. **BIO-03 / BIO-04:** complete historical pagination/checkpoints, safe deduplication, event taxonomy, time handling, TLS verification and request timeouts. Test reconnect, repeated/out-of-order pages and unknown codes with fixtures.
10. **QUEUE-01:** tenant-safe outbox relay/worker context, registered processing and scheduler, idempotency, retries and observable failures. Avoid cross-tenant discovery through a scope bypass that RLS still rejects.

Unavailable BioStar, Redis or live credentials must not block contracts, fake providers, tests and documentation. Clearly distinguish implemented infrastructure from operationally verified services.

### Wave 3 — administration and real worker workflow

11. **ADMIN-02:** configurable functionality groups, roles and user overrides with documented precedence and data scopes. Expose effective permissions and audit changes. Test both hidden UI and denied direct requests.
12. **WORKER-01:** replace My Day demo data and simulated submission with real authorized tasks and transitions. Include useful empty/loading/error states and safe double-click handling.
13. **FILES-01:** authorized upload, preview and download; visible progress and actionable failure handling; multi-photo evidence. A worker must be able to view submitted evidence and a manager must be able to review it.

### Wave 4 — mandatory timesheet delivery feature

14. **TIMESHEET-01:** usable filtering/selection and permission-aware timesheet detail; reproducible PDF snapshot with Georgian text/fonts and period/version identification. Reuse the current Timesheet model and approval workflow.
15. **TIMESHEET-EMAIL-01:** email one timesheet with recipient/subject preview, authorization, immutable attachment snapshot, queue, audit history and safe retry.
16. **TIMESHEET-EMAIL-02:** email a selected batch, including filtered/paginated selections. Support each employee receiving only their own sheet, and an explicitly authorized owner/accountant receiving the selected set. Provide preview, counts, skipped reasons, per-recipient status and retry-failed-only behavior.

Do not confuse submitting a timesheet for approval with emailing it. Do not expose other employees' attachments through shared recipients or CC/BCC. Do not show `delivered` when only SMTP acceptance is known. Handle an uncertain send outcome explicitly; do not promise exactly-once SMTP delivery. Queue retries must not intentionally resend already confirmed submissions.

Use Mail fake or an isolated test mail sink. Do not send real individual/bulk messages to employees or customers as a development test. Missing SMTP credentials block live delivery acceptance, not implementation and automated tests.

### Wave 5 — remaining platform completeness

17. **JOURNAL-01:** implement the missing Daily Journal pages against existing routes/domain, with role checks.
18. **PROJECT-01:** complete project member/WBS/document/activity workflows and useful task views without replacing working functionality.
19. **ASSETS-01:** complete issue/return/transfer/repair/loss workflows with immutable custody history and concurrency safeguards.
20. **NOTIFY-01:** notification center and owner-report Telegram integration. Use explicit account linking, permissions and safe summaries; Telegram is read-only reporting in this scope. Mock bot transport and keep credentials out of source/logs.
21. **PWA-01:** make offline drafts/upload replay durable and recoverable; do not display unsent work as submitted. Verify tenant/logout boundaries for locally cached information.
22. **OPS-01:** deployment/security/performance documentation, scheduler/workers/monitoring, backup and isolated restore procedure. Record real production prerequisites; do not deploy or change the host during this implementation session.

If a large ticket cannot be completed within available context, split it into independently testable slices and record the remaining slices. Do not label an entire module complete because its schema or page exists.

## Verification gates and storage safety

Read the current scripts/config first. Typical gates for this repository are:

```text
php artisan test --compact
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php vendor/bin/pint --test --format=json
npm run types:check
npm --prefix services/device-connector test
npm run build
git diff --check
```

The previously working Windows PHP executable was `C:\Users\Gvineee\.config\herd\bin\php84\php.exe`; use the actual installed executable and supported package scripts. Check PATH when invoking the build's PHP-dependent steps. Do not invent successful output if a tool is unavailable.

- Before database tests, verify the effective test connection/database. SQLite passing is not PostgreSQL/RLS acceptance. Run PostgreSQL tests with an isolated test database and restricted, non-superuser role where available.
- Never run `migrate:fresh`, `db:wipe`, reset seeders or destructive tests against `oda_crm` or another working database. Do not assume a database is disposable solely from its name.
- Test new migrations against a disposable database; document backfill, rollback limitations and any production rollout requirement. Do not automatically migrate the working installation overnight.
- Do not reset, clean, discard or broadly format the dirty worktree. Format only the touched scope; inspect generated diffs.
- No disabling RLS, tenant scopes, CSRF, TLS verification, authorization or MFA to make a scenario pass. Never grant the app superuser/BYPASSRLS as a workaround.
- Do not print secrets, cookies, API keys, full credential identifiers or complete `.env` contents. Keep debug traces out of user-facing pages.
- Do not commit, push, deploy, purchase services, change external privileges, open real doors or mass-send messages under this assignment. Use reviewable local changes and isolated verification.

## Autonomy, blockers and continuation

The user authorizes ordinary scoped implementation, documentation and isolated tests. Make reasonable reversible choices consistent with the codebase and record material decisions. Do not repeatedly ask whether to continue or which clearly prioritized ticket to start.

This instruction does not override the host tool's mandatory approval controls. Do not auto-click confirmations, bypass permission prompts or infer new authority for destructive/external actions. Record a blocked action and continue safe independent work when possible.

For missing hardware, browser access, email/Telegram secrets, external services or an unresolved product choice: state exactly what is missing, what can be verified locally and the next external acceptance step. Do not use fabricated production values. Defer only the blocked portion.

Before context compaction, session interruption or a known usage limit, persist:

- active ticket and implemented scope;
- exact changed files and contract/schema changes;
- exact latest test commands/results and unverified checks;
- the next executable step, not just “continue work”;
- blockers and independent ready tickets.

On resume, reload the runbook, checkpoint and current diffs; continue the recorded next step after checking it still applies. Do not repeat completed verified work or assume another agent made no changes.

Prompts cannot prevent provider usage limits, forced session termination, loss of network or computer sleep/shutdown. Do not claim you are working after execution has stopped. Keep the checkpoint current so a new session can resume safely.

## Completion and morning handoff

Do not declare the whole goal achieved unless all in-scope acceptance criteria are actually satisfied and outstanding live checks are explicitly resolved. If the session ends earlier, deliver a truthful partial handoff, not a false completion statement.

The final checkpoint/report must contain:

1. Completed tickets and visible user-facing improvements.
2. Files changed and migrations prepared, distinguishing applied from unapplied.
3. Exact test totals and static/build results; distinguish current results from the audit baseline.
4. Browser flows verified versus pending, and mock versus live integration evidence.
5. Remaining defects, security concerns and external prerequisites.
6. The next three concrete tasks and a copy-paste resume instruction.

When no eligible work remains, report the actual terminal condition and stop. Do not remain in artificial sleep loops or repeat passing commands merely to appear active.
