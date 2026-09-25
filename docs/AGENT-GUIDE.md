# ODA CRM — working instructions for an AI coding session

> `/CLAUDE.md` and `/AGENTS.md` are gitignored in this repository, so this
> tracked copy is what survives a clone. If you want it loaded automatically
> at session start, copy it to `CLAUDE.md` in the project root — or remove the
> `/CLAUDE.md` line from `.gitignore` and commit it there instead.

Georgian-language CRM for a construction company. Laravel 13 + Vue 3 + Inertia
+ TypeScript + Tailwind, PostgreSQL with row-level security, Redis.

**Read `docs/STATE.md` first** — it says what is finished, what is next, and
what is waiting on the owner.

---

## Language

The people using this product read Georgian. Every user-facing string — labels,
buttons, validation messages, empty states, toasts, PDF output — is Georgian.
Code, comments, commit messages and documentation are English.

When a status or type code needs to be shown to a person, put its Georgian name
in `resources/js/lib/labels.ts` and use it. Never render a raw enum value, a
raw permission string or a raw ISO timestamp to a user.

## Running it

```bash
php artisan test                  # Pest, SQLite in memory
php artisan test --compact
vendor/bin/phpstan analyse        # must be 0 errors
vendor/bin/pint --dirty           # formats what you touched
npm run types:check               # vue-tsc
npm run build
```

Everything above has to pass before a commit. `composer ci:check` runs the lot.

The dev database is PostgreSQL `oda_crm`, connected as the non-superuser role
`oda_app`. `pgsql_rls_test` is a disposable test database — `migrate:fresh`
against that one is fine.

The app is served by `php artisan serve` on **:8182**, exposed publicly through
a Cloudflare tunnel as **crm.syslab.ge**. The deployed revision IS this working
copy. The frontend runs from the production build, so `public/hot` must stay
absent — a running Vite dev server points asset URLs at an address no phone or
tunnel visitor can reach.

## Architecture

`docs/architecture.md` §3 is the module contribution convention and is
mandatory reading before writing code. In short:

- Domain logic lives in `app/Domain/<Module>/{Actions,Models,Services,Support}`.
- Controllers are thin: validate → Policy → Domain Action → Inertia response.
- Routes go in `routes/modules/web-*.php` (auto-globbed).
- A module adds a menu entry by shipping `config/modules/<module>-nav.php`.
  Never by editing `NavigationService` or a shared Vue component.
- `docs/data-model.md` is the schema; `docs/decisions.md` records why things
  are the way they are.

## Hard constraints

These are not style preferences. Breaking one is a defect even if tests pass.

- **Never grant the app's Postgres role superuser or BYPASSRLS.** Never disable
  RLS, tenant scopes, CSRF, authorization or MFA to make something pass.
- **Additive migrations only.** Never `migrate:fresh`, `db:wipe` or a
  destructive seeder against `oda_crm`.
- **PostgreSQL-specific behaviour needs a PostgreSQL test.** The suite runs on
  SQLite, which has hidden two real production bugs already (`max(uuid)`, and
  an RLS-blocked backfill). If a query uses a json path, a Postgres aggregate
  or RLS, test it against `pgsql_rls_test`.
- **`Rule::exists` runs beneath the tenant scope.** Any `exists:` rule on a
  foreign key must carry its own `->where('organization_id', ...)`, or another
  organization's id passes validation. Three separate holes of exactly this
  shape have been found here.
- **Hiding a menu item is not authorization.** Every route enforces its own
  Policy. A list endpoint must apply the same scoping its detail page does —
  a row visible in a list that the detail page refuses to open is a
  disclosure, not a cosmetic mismatch.
- **Never fabricate history.** A legacy record that cannot show what the new
  rules require keeps its status and gets flagged; it never receives an
  invented approval. The same applies to attendance: where an exit is unknown,
  say „გასვლა დაუდგენელია" rather than inventing hours.
- **BioStar is read-only in this phase.** No issuing or revoking cards, no
  creating BioStar users, no access-group changes, no opening doors, no direct
  SQL against its database, no exposing BioStar or RDP to the internet. Its
  credentials live only in the gitignored `.env` and never reach code, logs,
  exports or a commit.
- **Do not commit or push unless asked.** Say what you changed and let the
  owner decide.

## Writing tests

A test has to be able to fail for the reason it claims. When fixing a bug,
write the test that reproduces it first and watch it fail; when a test proves
an absence ("this does not appear"), add the companion that proves the
mechanism still works, or a broken feature will pass it silently.

Say what a test cannot cover rather than implying completeness. Several tests
here carry a note that the workflow they pin does not exist yet — that is
deliberate and worth keeping.

## Specifications

- `01-CRM-Audit-KA.md` — the live-site audit. All 26 items are closed.
- `02-Claude-Technical-Spec-KA.md` — the full product spec.
- `03-Construction-Task-Manager-Spec-KA.md` — **authoritative over spec 02 for
  anything about tasks and acceptance.** Its §16 acceptance table is the
  checklist; §8 defines the quantity arithmetic; §17 governs historical data.
