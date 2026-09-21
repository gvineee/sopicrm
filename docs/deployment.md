# ODA CRM — Deployment (OPS-01)

Real production prerequisites and procedures for putting ODA CRM on a VPS
while its device-connector(s) keep running on-premises next to each
organization's BioStar2 server(s). This document does not choose a hosting
provider — that remains an open decision (`docs/decisions.md` § Open
business-policy questions #6) — it documents what is already true about this
codebase's architecture and what any chosen host must satisfy.

Related: `docs/runbook.md` (local dev setup — read that first if you haven't
deployed anything yet), `docs/architecture.md` §4 (multi-tenancy/RLS) and §6
(Suprema device integration shape), `docs/decisions.md` DEC-016/DEC-026/
DEC-043/DEC-080..082 (the real, already-live BioStar2 integration this
deployment topology exists to carry forward, not a hypothetical).

---

## 1. Target architecture

```
                         Site A (on‑prem, e.g. an office/warehouse LAN)
                         ┌──────────────────────────────────────────┐
                         │  BioStar2 server (existing, unchanged)    │
                         │        ▲ LAN only, never exposed          │
                         │        │ HTTPS (BIOSTAR_BASE_URL)          │
                         │  device-connector (Node, this repo,       │
                         │  services/device-connector)                │
                         │        │                                   │
                         └────────┼───────────────────────────────────┘
                                  │ OUTBOUND ONLY, HTTPS 443
                                  │ Sanctum machine token
                                  │ (DEVICE_CONNECTOR_TOKEN, org‑bound)
                                  ▼
   ┌──────────────────────────────────────────────────────────────────┐
   │ VPS — public HTTPS domain (e.g. crm.example.ge)                  │
   │                                                                    │
   │  nginx (TLS termination) ──▶ php-fpm ──▶ Laravel app              │
   │                                   │                                │
   │                                   ├── oda-crm-queue.service        │
   │                                   │   (queue:work redis)           │
   │                                   ├── oda-crm-schedule.timer       │
   │                                   │   (schedule:run every minute,  │
   │                                   │    drives outbox:relay)        │
   │                                   ├── PostgreSQL (oda_app role,    │
   │                                   │   RLS enforced, NOT superuser) │
   │                                   └── Redis (queue/cache/session)  │
   └──────────────────────────────────────────────────────────────────┘

                         Site B (a second physical location, if any)
                         ┌──────────────────────────────────────────┐
                         │  its OWN BioStar2 server                  │
                         │  its OWN device-connector instance        │
                         │  its OWN DEVICE_CONNECTOR_TOKEN,           │
                         │  scoped to only that site's device UUIDs  │
                         └──────────────────────────────────────────┘
                                  │ same outbound-only pattern
                                  ▼  (same VPS domain)
```

**The key property, already true in this codebase, not something this
deployment pass invents:** `services/device-connector` always *initiates*
the connection to Laravel (`DEVICE_CONNECTOR_LARAVEL_URL`) — heartbeat,
event upload, command polling are all connector → Laravel HTTP calls. Laravel
never opens a connection back into a site's LAN. This means:

- **No VPN and no inbound port-forward is required on any site's local
  network or router.** The only outbound requirement at each site is TCP 443
  to the VPS's domain.
- The BioStar2 server itself never needs to be reachable from the internet —
  only the connector (running on a machine with LAN access to it) needs to
  reach it, which is already how local dev works (`BIOSTAR_BASE_URL` pointed
  at a LAN IP).
- "Live" updates are really "polled every `DEVICE_CONNECTOR_POLL_INTERVAL_MS`
  (default 5000ms) and pushed immediately" — not a persistent socket. This is
  already the deployed real-hardware-verified behavior (DEC-081), not a new
  design.

**Multi-site:** if there is more than one physical location, each one runs
its **own** `device-connector` process (its own Docker container / systemd
service / host — whichever is convenient at that site) with its **own**
machine token scoped to only that site's device UUIDs (`§2` below). Do not
point two sites' connectors at the same token, and do not run one connector
process that tries to reach two different LANs — the connector's
`DEVICE_CONNECTOR_DEVICE_IDS` list and its single `BIOSTAR_BASE_URL` are
both singular by design.

---

## 2. Machine-token provisioning per site connector

This command already exists and is exercised in local dev
(`docs/runbook.md` § Device-connector operations, `app/Console/Commands/
IssueMachineToken.php`) — the only thing that changes for production is
*where* the resulting connector points (`DEVICE_CONNECTOR_LARAVEL_URL` →
the real VPS domain instead of `http://app:8000/...`) and that the token is
stored in that site's own secret store, not in this repo.

```bash
# Run ONCE per site, on the VPS (or anywhere with artisan access to the
# production database) — never on the site machine itself.
php artisan tokens:issue-machine <ORGANIZATION_UUID> device-connector-site-1 \
  --ability=device-connector:heartbeat.write \
  --ability=device-connector:commands.read \
  --ability=device-connector:commands.write \
  --ability=device-connector:events.write
```

- The printed plaintext token is shown **exactly once**. Copy it directly
  into that site's `device-connector` deployment's own environment
  (`DEVICE_CONNECTOR_TOKEN`) — a local `.env` file on the site machine, a
  Docker secret, or the site's own secrets manager. It is covered by this
  repo's existing `.gitignore` `.env` exclusion the same way local dev
  secrets are — it must never be committed, and never be pasted into a nav
  config, migration, or any tracked file.
- A second site gets a **second**, independently-issued token with a name
  like `device-connector-site-2`, scoped only to that site's own device
  UUIDs via `DEVICE_CONNECTOR_DEVICE_IDS` on that site's connector — the
  token itself doesn't limit which devices it can heartbeat/report for
  (abilities are per-*action*, not per-device), so device scoping is
  enforced by configuring the connector with only that site's real device
  UUIDs, matching the existing single-site local-dev convention.
- **Rotation:** issue a new token (`tokens:issue-machine` again with a new
  name, e.g. `device-connector-site-1-2026-q2`), update that site's
  `DEVICE_CONNECTOR_TOKEN`, restart that site's connector, confirm its
  `/health` endpoint reports normally and a heartbeat/event lands in
  Laravel, and only then revoke the old token's `personal_access_tokens`
  row (delete it, or add a revoke command if this becomes routine enough to
  warrant one — none exists yet). This mirrors the rotation note already in
  `docs/runbook.md`.

At each site, point the connector at the real domain instead of the
Docker-internal hostname used locally:

```
DEVICE_CONNECTOR_LARAVEL_URL=https://crm.example.ge/api/v1/device-connector
DEVICE_CONNECTOR_TOKEN=<value from tokens:issue-machine, this site only>
DEVICE_CONNECTOR_DEVICE_IDS=<comma-separated UUIDs for THIS site's devices only>
DEVICE_CONNECTOR_MODE=suprema
BIOSTAR_BASE_URL=https://<biostar-lan-ip-or-hostname>
BIOSTAR_USERNAME=<biostar login>
BIOSTAR_PASSWORD=<biostar password>
BIOSTAR_VERIFY_TLS=true            # prefer this + BIOSTAR_CA_CERT_PATH over false
BIOSTAR_CA_CERT_PATH=/path/to/biostar-ca.pem   # if the server uses a self-signed cert
BIOSTAR_WRITE_DISPATCH_ENABLED=false           # keep false — see §5
```

The connector itself can keep running however is convenient at that site —
`docker compose -f infra/docker-compose.yml up -d device-connector` (pointed
at the real `DEVICE_CONNECTOR_LARAVEL_URL` above via an env override) works
unchanged, or a bare `node src/index.js` under a local systemd/pm2 unit on a
small on-site machine. This document does not mandate one, since the site
machine is out of this repo's control.

---

## 3. Process supervision (VPS side)

### 3.1 Web: nginx + php-fpm, not `artisan serve`

`infra/docker/php/Dockerfile`'s own comment already states this plainly:
it's a local-dev image with "no opcache tuning, no multi-stage slim build."
`php artisan serve` is PHP's built-in single-threaded development server —
adequate for local dev and CI, not for serving real concurrent users.
Production web serving should be:

- **nginx** terminating TLS (or a load balancer in front of it) and proxying
  to **php-fpm** — the standard, well-documented Laravel deployment shape.
  A minimal nginx server block (adapt paths/domain):

  ```nginx
  server {
      listen 443 ssl http2;
      server_name crm.example.ge;

      root /var/www/oda-crm/public;
      index index.php;

      ssl_certificate     /etc/letsencrypt/live/crm.example.ge/fullchain.pem;
      ssl_certificate_key /etc/letsencrypt/live/crm.example.ge/privkey.pem;

      location / {
          try_files $uri $uri/ /index.php?$query_string;
      }

      location ~ \.php$ {
          fastcgi_pass unix:/run/php/php8.4-fpm.sock;
          fastcgi_index index.php;
          include fastcgi_params;
          fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      }

      location ~ /\.(?!well-known) { deny all; }
  }

  server {
      listen 80;
      server_name crm.example.ge;
      return 301 https://$host$request_uri;
  }
  ```

- **Tradeoff, stated honestly:** setting up php-fpm + nginx is more initial
  work than reusing `php artisan serve` behind a reverse proxy. For a
  genuinely small/early deployment, `infra/systemd/oda-crm-web-fallback.
  service` (new, this pass) runs `artisan serve` under systemd as a stopgap
  — it is explicitly labeled in its own file as not recommended for real
  load (single-threaded, no opcache) and should be replaced with php-fpm as
  soon as practical, not treated as the permanent answer.

### 3.2 Queue worker and scheduler: real systemd units, not ad hoc

Two new unit files, `infra/systemd/oda-crm-queue.service` and
`infra/systemd/oda-crm-schedule.{service,timer}` (this pass). They target a
**Linux** VPS and use plain `php` on `PATH` — this is a deliberate
departure from this Windows dev machine's Herd-specific path
(`C:\Users\...\herd\bin\php84\php.exe`, referenced throughout
`docs/runbook.md`), which has no meaning on a real Linux server; a standard
`apt`/package-manager PHP install puts `php` on `PATH` directly.

`oda-crm-schedule.timer` is what actually runs
`Schedule::command('outbox:relay')->everyMinute()` (`routes/console.php`,
QUEUE-01) in production — without it, outbox rows accumulate forever exactly
as they did before QUEUE-01 scheduled it locally. Do not substitute a
literal crontab entry unless you have a specific reason to prefer it; the
systemd timer gives you `systemctl status`/`journalctl` visibility for free.

Install (see each unit file's own header comment for the exact commands):

```bash
sudo cp infra/systemd/oda-crm-queue.service /etc/systemd/system/
sudo cp infra/systemd/oda-crm-schedule.service /etc/systemd/system/
sudo cp infra/systemd/oda-crm-schedule.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now oda-crm-queue.service
sudo systemctl enable --now oda-crm-schedule.timer
systemctl list-timers oda-crm-schedule.timer   # confirm it's actually scheduled
```

Both units run as a dedicated `oda-crm` system user (create with
`useradd --system --no-create-home oda-crm` or equivalent), not `root` and
not the default web server user, so a queue-job bug can't casually write
outside `/var/www/oda-crm`.

### 3.3 Postgres / Redis: managed vs. self-hosted — open decision

`docs/architecture.md` §1 leaves this open ("Foundation decides based on ops
simplicity") and `docs/decisions.md` § Open business-policy questions #6
explicitly defers the hosting choice. Both are valid:

| Option | Tradeoff |
|---|---|
| Self-hosted on the same VPS (`apt install postgresql redis-server`) | Cheapest, simplest to start; you own patching, backup automation (§7), and failover. Fine for a first production deployment at modest scale. |
| Managed Postgres/Redis (a cloud provider's managed database product) | Automated backups/PITR and failover come built-in, directly serving the `docs/decisions.md` RPO ≤ 15 min / RTO ≤ 4h target — but costs more and you must still confirm the provider lets you connect as a **non-superuser** role for RLS (§5) to keep working, since some managed Postgres products restrict role creation. |

Whichever is chosen, the critical constraint from `docs/architecture.md` §4
carries over unchanged: **the application's runtime DB role must not be a
superuser and must not own the tables it queries**, or every Row-Level
Security policy in this codebase silently becomes a no-op. Verify this
explicitly on the chosen host — it is not automatic on every managed
Postgres product.

---

## 4. TLS and domain

- `APP_URL` must be the real `https://` domain (e.g.
  `https://crm.example.ge`) in production `.env` — not
  `http://localhost:8182` (the current local-dev value). Laravel uses
  `APP_URL` to generate absolute URLs (signed download links per DEC-010,
  password-reset/email-verification links, the device-connector's own
  contract base URL).
- `SESSION_SECURE_COOKIE=true` once served over real HTTPS — `.env.example`
  already carries this exact instruction as a comment (line 45-49) precisely
  for this moment; it is not optional for a spec-section-21-compliant
  production deployment.
- **Certbot/Let's Encrypt** is the concrete free option for the nginx
  config in §3.1 (`certbot --nginx -d crm.example.ge`), unless the chosen
  host already terminates TLS for you (some PaaS-style hosts do) — in that
  case skip certbot and just ensure `APP_URL`/`SESSION_SECURE_COOKIE` above
  still reflect the real external HTTPS URL your users hit.
- No Sanctum SPA/stateful-domain configuration exists in this codebase yet
  beyond the machine-token (personal-access-token) usage already documented
  in §2 — the web app itself uses plain Laravel session auth (cookie + CSRF,
  per `docs/architecture.md` §1), which needs no additional Sanctum
  `stateful` domain config for a single first-party domain like this.

---

## 5. Security checklist before going live

Everything in this list is either an already-enforced code constraint (don't
undo it) or a genuine prerequisite that doesn't exist yet (don't claim it
does):

- [ ] **Postgres runtime role is `oda_app`-equivalent: non-superuser, does
      not own its tables.** Never grant `BYPASSRLS` or superuser to the
      application's connection role on the VPS, exactly as already enforced
      in CI (`docs/runbook.md` § CI pipeline step 2) and local dev
      (DEC-026/DEC-043). This is the single most important item on this
      list — get it wrong and every RLS policy in this codebase (tenant
      isolation, the QUEUE-01 outbox relay policies) silently stops
      protecting anything.
- [ ] **Bootstrap the first real platform admin** via
      `php artisan admin:platform-admin grant <email> --as=<bootstrap-actor-email> --reason="initial production setup"`
      (ADMIN-01, `app/Console/Commands/GrantPlatformAdmin.php`) — there is no
      web UI for this by design (bootstrap-only, server-access-gated), and no
      seeded platform admin exists outside the local dev seeder's
      non-production guard (`docs/runbook.md`, `DatabaseSeeder.php`).
- [ ] **`BIOSTAR_WRITE_DISPATCH_ENABLED` stays `false`** (both the
      connector-side env var and Laravel's own `devices.
      biostar_write_dispatch_enabled` config) in production **until a real
      hardware pilot separately confirms the write path**, per BIO-01's
      explicit read-only enforcement and DEC-082's own unresolved,
      not-yet-live-confirmed status for `add_user`. Turning this on is a
      deliberate, supervised action (see `services/device-connector/
      README.md` § Testing the live write path), never a default production
      setting.
- [ ] **No `.env` file, connector token, or BioStar credential is ever
      committed.** `.gitignore` already excludes `.env`/`.env.backup`/
      `.env.production` at the repo root; each site's `device-connector`
      `.env`-equivalent lives only on that site's own machine/secret store,
      never in this repository.
- [ ] **Real SMTP credentials are a prerequisite for TIMESHEET-EMAIL-01/02,
      which are not implemented yet** (Wave 4, `docs/claude-overnight-goal.
      md` — no code for either ticket exists in this codebase as of this
      writing). Do not configure production SMTP under the assumption that
      timesheet emailing already works; `MAIL_MAILER`/`MAIL_HOST` etc. in
      `.env.example` currently point at the local Mailpit catcher only.
- [ ] **`APP_DEBUG=false`** in production `.env` (`.env.example` currently
      sets `APP_DEBUG=true`, a local-dev default) — a debug-mode stack trace
      page leaks source paths, config values, and potentially query
      parameters to any visitor who triggers an unhandled exception.
- [ ] **`AGENTS.md`/`CLAUDE.md`/`.mcp.json` and other agent-tooling files
      stay out of the deployed artifact** — already excluded from git via
      `.gitignore`, so a `git pull`-based deploy already won't ship them;
      worth confirming explicitly if the deploy mechanism ever changes to
      something that copies the whole working tree instead (e.g. `rsync -a`
      without an exclude list).

---

## 6. Monitoring

Nothing here is new infrastructure — this section says what to actually
look at, using panels/fields this codebase already produces:

- **Per-site device-connector health** — each connector's own `GET /health`
  endpoint (not exposed publicly; check it over the site's LAN, or have the
  connector's own process supervisor alert on repeated non-200 responses):
  `consecutiveFailures`/`backoffUntil` (BIO-04's exponential-backoff state —
  a connector stuck in backoff means it can't reach BioStar, not that it
  crashed), plus whatever heartbeat-age/event-lag fields
  `services/device-connector/src/index.js` reports as of the version
  deployed.
- **The same import-health data, from Laravel's side** — `Devices/Show.vue`'s
  "მოვლენების იმპორტის მდგომარეობა" panel (BIO-03): last confirmed
  checkpoint timestamp, open (unresolved) `data_gap`/`out_of_order_events`/
  `clock_drift` anomaly counts, and the `DeviceSyncCommand` status backlog
  (pending/retry/failed/dead_letter) per device. This is the first place to
  look if a site "seems to have stopped updating" — it distinguishes "the
  connector is down" from "the connector is up but events aren't landing"
  from "everything's fine, nothing happened."
- **Queue health** — Laravel's own `failed_jobs` table (`php artisan
  queue:failed` or a query against it) for jobs that exhausted their
  retries; `journalctl -u oda-crm-queue.service` for the worker process's
  own stdout/stderr (crashes, restarts).
- **Scheduler health** — `systemctl status oda-crm-schedule.timer` /
  `journalctl -u oda-crm-schedule.service` confirm `outbox:relay` is
  actually firing every minute; an outbox backlog that only grows is the
  first symptom of the scheduler silently not running (exactly the gap
  QUEUE-01 found and fixed for local dev — the same class of gap can recur
  in production if the timer is never installed).
- **Postgres/Redis basic health** — standard `pg_isready` / `redis-cli
  ping` checks from whatever uptime-monitoring tool is already in use
  operationally; nothing app-specific to add here beyond what any Postgres/
  Redis deployment already needs.

No centralized dashboard aggregating all of the above exists yet in this
codebase — this section lists where to look manually today; building a
single ops dashboard is future work, not claimed as done here.

---

## 7. Backup and restore

**No backup automation exists in this codebase yet.** This section is a
recommendation for what to set up, not a description of something already
running — stated honestly per `docs/decisions.md` § Open business-policy
questions #6's own RPO/RTO caveat ("to be confirmed against actual infra,
not assumed").

- **Recommended baseline:** nightly `pg_dump` (custom format,
  `pg_dump -Fc oda_crm > oda_crm_$(date +%F).dump`) via a cron/systemd-timer
  job on the VPS, retained on separate storage from the VPS itself (object
  storage, a second host, etc. — not just another directory on the same
  disk, which doesn't survive a disk failure). If the chosen host is a
  managed Postgres product (§3.3), prefer its built-in automated
  backup/PITR feature over a hand-rolled `pg_dump` cron, since it typically
  gets you point-in-time recovery for free.
- **Attachments** (private S3-compatible disk, DEC-010): whatever
  S3-compatible provider is chosen for production should have object
  versioning or its own backup mechanism enabled — a lost/corrupted
  attachment has no separate backup path in this codebase beyond the
  storage provider's own durability guarantees.
- **Isolated restore drill — what it must verify, not just "the dump
  restores":**
  1. Restore the dump into a **fresh, isolated** Postgres instance (never
     directly into the live production database) —
     `pg_restore -d oda_crm_restore_test oda_crm_2026-xx-xx.dump`.
  2. Recreate the `oda_app` role as **non-superuser** on that restored
     instance before connecting the app to it — a raw `pg_restore` run as a
     superuser does not itself recreate the role-permission boundary; that
     is a separate, deliberate step (mirrors `docs/runbook.md`'s local
     `CREATE ROLE oda_app ... ` step).
  3. Confirm RLS policies exist on the restored tables (`\d+ <table>` in
     `psql`, or query `pg_policies`) — `pg_dump`/`pg_restore` do carry RLS
     policy definitions by default, but this must be verified on the actual
     restored instance, not assumed, exactly as `docs/agent-handoff.md`'s
     QUEUE-01 entry did for its own new policies.
  4. Run a real query as the restored `oda_app` role for two different
     `organization_id`s and confirm tenant isolation still holds post-restore
     — the same class of check `tests/Feature/Auth/TenantIsolationRlsTest.php`
     already does against the live dev/CI database, run once by hand against
     the restore.
  5. Record: restore duration (informs a real RTO number instead of the
     unconfirmed target in `docs/decisions.md`), dump age at time of restore
     (informs a real RPO number), and the date this drill was last run —
     none of this has been run yet as of this writing; this is the
     procedure for whoever runs the first one.

---

## Pending infrastructure decisions

Tracked here rather than guessed, consistent with `docs/decisions.md` §
Open business-policy questions' own pattern (specifically extends item #6):

1. **Hosting provider** — not yet chosen. This document's nginx/php-fpm/
   systemd shape works on any plain Linux VPS; a PaaS-style host (which
   might replace §3's systemd units with its own process-manager
   conventions) is a different, not-yet-made choice.
2. **Self-hosted vs. managed Postgres/Redis** — see §3.3's tradeoff table.
3. **Backup storage location/provider** — see §7; no specific object-storage
   or off-VPS destination has been chosen yet.
4. **SMTP provider for TIMESHEET-EMAIL-01/02** — irrelevant until those
   tickets are actually implemented (Wave 4, not started); do not provision
   this ahead of that work.
