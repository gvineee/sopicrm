# BioStar — where this stopped, and what is left

Written 2026-09-25. Read `docs/STATE.md` §5 first; it holds the measured facts
about the live install. This file is only the BioStar thread: what works, what
is blocked, and on what.

---

## What works, verified against the real server

- **People.** `biostar:sync-people` imports BioStar users as employees with
  status `pending_verification`. They carry `employees.biostar_user_id`, unique
  per organization, which survives a card being reissued. BioStar's built-in
  `Administrator` (user id 1) is skipped.
- **Identity merge.** `biostar:adopt-person` moves a provisional record's
  BioStar id, card and swipe history onto an employee the organization already
  had. A human decides; the sync never guesses.
- **Verification.** `VerifyProvisionalEmployeeAction` requires a department and
  activates the person. Until then `TaskPolicy` and both task FormRequests
  refuse to make them accountable for work.
- **Events.** `biostar:import-events` backfills the log through the same
  `IngestRawAccessEventAction` and `BiostarEventTaxonomy` the device-connector
  uses. Scheduled every ten minutes with a two-day overlap. Idempotent.
- **Live state.** 451 raw events imported, 21 of them `access_granted` and all
  attributed to `011 | ირაკლი ღვინერია` via card `69410222` and BioStar id `2`.

## What is blocked, and on whom

### 1. No attendance hours — waiting on the OWNER, not on code

Both doors have `exit_device: NONE`. BioStar itself does not know which reader
is an entry and which an exit, so both devices carry
`reader_role = 'unspecified'` and no session can be paired.

This is now visible rather than silent: reconstruction raises an
`undirected_reader` anomaly naming both readers
(„წამკითხველს მიმართულება არ აქვს მითითებული"). It clears itself once the roles
are set.

The owner has to choose one of:

- **a.** Configure an exit reader in BioStar so a door has both sides. This is
  the only option that gives genuinely measured entry and exit.
- **b.** Designate `544452272 შემოსასვლელი` as `in` and
  `544452273 აღრიცხვა` as `out` at `/devices/{id}/edit`, and have staff badge
  out at the second reader. **Note that today every single real badge read is
  on the gate and the აღრიცხვა reader has none**, so until people actually use
  it this produces `missing_out` every day — honest, but not hours.
- **c.** A single-reader toggle policy (first read of a day is an entry, next
  is an exit). This does NOT exist in the code and should not be added without
  the owner explicitly asking: the 2026-09-20 test burst has sixteen reads in
  ten minutes, which toggling would turn into nonsense hours.

Do not pick one of these on the owner's behalf.

### 2. The device clock — waiting on ONE new badge read

The owner corrected the server and device time on 2026-09-25 around midday. It
could not be confirmed, because BioStar exposes no read-only endpoint for a
device's current clock:

| probe | answer |
|---|---|
| `GET /api/devices/{id}/time` | `Request is not supported` |
| `GET /api/devices/{id}/status` | `Request is not supported` |
| `GET /api/server/datetime` | `Permission Denied` |

The only evidence is an event's own pair of timestamps. The newest event at the
time of checking was `id 579`, from **before** the correction, still showing
`+10805s` (+3h).

**Next step: ask for one badge swipe, then run `php artisan biostar:clock-check`.**
It prints the measured offset per reader from each one's newest event. Under
300 seconds means the correction took.

Once it reads clean, the open `clock_drift` anomaly is stale and a human can
resolve it at `/attendance/anomalies`. Do not resolve it from a script — a
human closing an anomaly is the record that somebody looked.

## Things found on the way that are worth knowing

- **The database has seven organizations**, five of them named `ODA Demo`. The
  real one is `01a0aae2-1013-731a-87b4-6f37c76231ac` (now pinned in `.env` as
  `BIOSTAR_ORGANIZATION_ID`). The rest look like leftovers from earlier
  sessions and test factories. **Ask the owner before deleting any of them** —
  they are cheap to keep and impossible to recover.
- `CLAUDE-TEST-001 | CLAUDE TEST DELETE ME` is still on the roster with card
  `9999999001`, from an earlier session. Same rule: ask first.
- `attendance:process-incremental` keeps a per-employee checkpoint, so it will
  NOT reprocess an employee whose events it has already seen. After changing
  reconstruction logic, run the action directly for the affected employee, or
  the change appears to do nothing.
- The BioStar event search's date condition filters on `datetime` — the
  device's own wrong clock. Never use it. See `docs/STATE.md` §5.

## What was deliberately not done

- No write path to BioStar. No cards issued or revoked, no users created, no
  access groups changed, no doors opened, no device configuration touched, no
  direct SQL against its database. That constraint holds for this whole phase.
- The device-connector service (`services/device-connector`) is still the
  intended low-latency path and is unchanged. The scheduled import is a safety
  net beside it, not a replacement — they share the ingest's dedup key and
  cannot double-count.
