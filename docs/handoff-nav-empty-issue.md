# Handoff: sidebar navigation shows empty after login

**Reported by:** user, live browser session at http://sopicrm.test
**Status:** two real bugs found and fixed by Claude; symptom reportedly persists after a normal page refresh; root cause of the *remaining* symptom not yet confirmed. Needs a second pair of eyes, ideally from whoever owns the Companies integration.

## Symptom

After logging in successfully (`test@example.com` / `password`), only the Dashboard page renders. The sidebar nav is empty — no Employees / Devices / Credentials / Companies / Daily Journal links, even though these modules exist and their routes work if visited directly by URL.

## Two real bugs already found and fixed (confirmed working)

1. **`app/Domain/Shared/Services/AuditLogger.php`** — any failed login attempt crashed with `SQLSTATE[42501] ... row-level security policy for table "audit_events"`. Root cause: `AuditLogger::log()`'s explicit-`$organizationId` override path (used by `LogAuthenticationEvent` and `AcceptEmployeeInviteAction`, both pre-auth-context) bypassed Eloquent's tenant scope via `withoutTenantScope()` but never set the Postgres `app.current_org_id` session GUC that RLS actually enforces at the database level — those are two independent enforcement layers. Fixed by calling `set_config('app.current_org_id', ...)` explicitly in that override branch, mirroring the pattern already used in `DatabaseSeeder`. Verified directly: simulated `Failed` event no longer throws.

2. **Missing permissions in the live dev database.** `permissions` table had zero rows for `devices.*`, and likely others added after the very first `db:seed` run — `DevicesPermissionsSeeder`/`ProjectsPermissionsSeeder` exist in code but were never actually executed against this persistent dev DB (each `*PermissionsSeeder.php` addition needs `db:seed` re-run to take effect; nobody had re-run it since Devices/Projects seeders were added). Fixed by running `php artisan db:seed --class=AggregatingPermissionsSeeder --force` (idempotent, `firstOrCreate`-based, safe to re-run) + `php artisan permission:cache-reset`. Verified directly in tinker: the demo owner user (`01a0aae3-9535-731f-9e7b-9c61cbba5464`, org `01a0aae3-948e-70d4-bffa-44065fa28054`) now returns `can('devices.view') === true`, and `App\Domain\Shared\Services\NavigationService::groupsForUser()` returns 5 populated groups (Companies, Projects→Daily Journal, Devices→Devices/Credentials, Employees→Employees/Teams, Overview→Dashboard) for that exact user.

Both fixes are committed to the working tree (not yet git-committed as of this file — check `git status` / `git diff app/Domain/Shared/Services/AuditLogger.php`).

## What's NOT yet explained

After both fixes above, the user refreshed the browser and still reports an empty nav. Given the server-side proof above, the leading hypotheses, in order of likelihood:

1. **Stale page / didn't actually get a fresh server response** — Inertia shares `navGroups` on every response via `HandleInertiaRequests::share()`, so a genuine full reload should pick it up. Rule out first: hard reload (Ctrl+Shift+R) or a clean logout/login cycle, and check the Network tab for the actual `navGroups` value in the Inertia JSON response (or the initial page's embedded `data-page` JSON on a full load).
2. **The real logged-in browser session resolved to a *different* `test@example.com` row than the one Claude verified.** There are multiple duplicate `test@example.com` accounts across different orgs in this dev DB (leftover from repeated `db:seed` runs — see below). Permissions/roles are global (`role_has_permissions` has no team scoping — that's correct spatie/teams design), so this *should* no longer matter after the re-seed, but hasn't been independently verified for a duplicate account other than the one Claude checked.
3. **Something about the Companies layer** (`app/Domain/Companies/*`, `CurrentCompany`, `current_company_id`, `CompanyMembership`) that Claude does not fully understand the design intent of. `SetCurrentOrganization::resolveCompanyId()` sets `CurrentCompany` based on `$principal->current_company_id` matching `$principal->organization_id`. If any Policy, global scope, or the Devices/Employees policies were *supposed* to also require a valid current-company context (not currently visible in the Policy classes Claude read — `DevicePolicy`, `CredentialPolicy` only check `$user->can(...)` + `organization_id`), and something upstream silently fails when company context is missing/mismatched, that could produce this symptom without throwing a logged exception. **This is the part that most needs the Companies feature's original author** — Claude does not know why Companies was introduced alongside Organization or what invariants it's supposed to enforce.
4. Stale built frontend assets (`public/build`) vs. current `resources/js` source — unlikely to cause an *empty* nav specifically (would more likely cause a Vite-manifest error, which the log doesn't show for `AppSidebar`/`NavMain`), but worth a `npm run build` re-run to rule out.

## Suggested split

**Claude (Devices web UI scope) will:**
- Re-verify with a real HTTP session (not just tinker) using a browser-equivalent tool, to pin down exactly what `navGroups` the live server returns for the actual current session.
- Check whether other duplicate `test@example.com` accounts behave identically post-fix.

**Codex / whoever owns Companies + Auth/Shared, please:**
- Confirm whether `CurrentCompany`/`current_company_id`/`CompanyMembership` is *supposed* to gate anything relevant to navigation or Policy checks right now, and if so, where — this file's author (Claude) could not find such a dependency in the Devices/Employees Policy classes but may be missing something in Companies' own code or a global scope.
- Sanity-check `config/permission.php`'s `teams` + `column_names.team_foreign_key` setting against `model_has_roles`'s actual `organization_id` column name, and against how `CurrentCompany`/company membership interacts with permission team scoping, if at all.
- If you find the real cause, please update this file with what it was before deleting it, so the fix is documented alongside the two above.

## Verified-good reference commands (safe, read-only unless noted)

```
php artisan tinker --execute="app(Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId('01a0aae3-948e-70d4-bffa-44065fa28054'); $u = App\Models\User::whereKey('01a0aae3-9535-731f-9e7b-9c61cbba5464')->first(); echo $u->can('devices.view') ? 'YES' : 'no';"
```

Demo owner account for testing: `test@example.com` / `password`, org `01a0aae3-948e-70d4-bffa-44065fa28054`, user id `01a0aae3-9535-731f-9e7b-9c61cbba5464`. Note: this email has duplicates across other orgs from repeated dev seeding — not yet cleaned up, low priority but worth doing eventually (`Organization::where('name','ODA Demo')` will list them all).
