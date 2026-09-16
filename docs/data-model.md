# ODA CRM — Data Model (P0 + P1 scope)

Source of truth: `ODA_CRM_Claude_Code_Spec.md` v1.2 section 19 (entity map) plus the constraint language embedded in sections 5–11. This file is what every module-building agent implements real Laravel migrations against. Entities from section 19 that belong to P2+ (Item/Unit/UnitConversion/Warehouse/StockMovement/StockReservation, Supplier/PurchaseRequest/Quotation/PurchaseOrder/GoodsReceipt/SupplierInvoice, CostCode/BOQRevision/BOQLine/BudgetRevision/CostLedgerEntry/ChangeOrder/Expense, Contact/Opportunity/Proposal/Contract/Inspection/Defect/SafetyIncident/RFI/Submittal) are named at the end for forward-reference only; they are not designed in detail here and must not be migrated during Foundation.

Conventions applying to every table below unless stated otherwise (see `docs/architecture.md` § 5 for rationale):
- `id UUID PRIMARY KEY DEFAULT uuid_generate_v7()` (or app-generated UUIDv7).
- `organization_id UUID NOT NULL REFERENCES organizations(id)` on every business table (omitted from the column list below for brevity, but implied everywhere except `organizations` itself and pure lookup/global tables explicitly marked "global").
- `created_at timestamptz NOT NULL`, `updated_at timestamptz NOT NULL`, `version integer NOT NULL DEFAULT 1`.
- Money columns: `numeric(14,2)`, never float. Rate/unit-price columns needing sub-cent precision before rounding: `numeric(18,4)`.
- Every composite unique/FK that involves a tenant-scoped table includes `organization_id` in the key.
- RLS enabled + forced on every business table (see architecture.md § 4).

---

## Domain: Access (Organization, User, Role, Permission, Membership, ProjectMembership, Session)

### `organizations`
- `id`, `name`, `legal_name` nullable, `default_currency` char(3) default `'GEL'`, `default_timezone` default `'Asia/Tbilisi'`, `is_active` bool, `created_at`, `updated_at`.
- No `organization_id` column (this IS the tenant root).

### `users`
- `id`, `organization_id` (a user's **primary/home** organization — see note), `name`, `email` (unique per organization: `unique(organization_id, email)`), `phone` nullable, `password_hash`, `personal_id_number_encrypted` nullable (encrypted-at-rest cast; restricted-visibility field per section 5), `photo_attachment_id` nullable FK `attachments`, `current_organization_id` FK `organizations` (defaults to `organization_id`; supports future multi-org membership per user without redesign — P4 scope, column exists now per spec's "start with one company but every business record carries organization_id so more companies can be added later"), `is_active`, `mfa_secret_encrypted` nullable, `last_login_at`, timestamps, `version`.
- Distinct from `Employee` (spec section 5: "Employee და login account ცალკე ცნებებია"): a `User` is a login account; an `Employee` may or may not have one.

### `roles` (spatie/laravel-permission `roles` table, extended)
- Standard spatie columns (`id`, `name`, `guard_name`) + `organization_id` nullable (null = global/system role template; spatie's "teams" feature is used with `organization_id` as the team foreign key so roles can be tenant-scoped later without a redesign).
- Seeded roles (spec section 3, fixed `name` slugs): `owner`, `system_admin`, `hr`, `finance`, `project_manager`, `foreman`, `warehouse_keeper`, `employee`, `procurement_manager`, `qa_safety`, `client_subcontractor`.

### `permissions` (spatie/laravel-permission `permissions` table)
- Standard columns; `name` convention `<module>.<resource>.<action>` (e.g. `payroll.pay-runs.approve`).

### `model_has_roles` / `model_has_permissions` / `role_has_permissions`
- Standard spatie pivot tables. A user may hold multiple roles (spec section 3: "მომხმარებელს შეიძლება რამდენიმე როლი ჰქონდეს").

### `memberships`
- `id`, `user_id` FK `users`, `organization_id`, `is_primary` bool.
- Purpose: explicit table (rather than relying solely on spatie's team pivot) recording which organizations a user account can access at all, ahead of P4 multi-org UX. Unique: `unique(user_id, organization_id)`.

### `project_memberships`
- `id`, `user_id` FK `users`, `project_id` FK `projects`, `role_context` (e.g., `manager`, `foreman`, `member`, `client_viewer` — informational; actual permission still comes from the user's `roles`), `added_by_user_id`, `removed_at` nullable (soft revoke, keeps history — spec: "პროექტის წევრობა და როლის უფლებები ერთად განსაზღვრავს წვდომას", so this table is checked in every project-scoped Policy alongside the role permission).
- Unique: `unique(organization_id, user_id, project_id)` where `removed_at IS NULL` (partial unique index).

### `sessions`
- Laravel's standard session table (`id`, `user_id` nullable, `ip_address`, `user_agent`, `payload`, `last_activity`) — used for the database session driver; `organization_id` not required here (session content already scopes via `user_id`), but login audit correlates to `AuditEvent`.

---

## Domain: Employees (Employee, Employment, Team, TeamMembership, RateHistory, EmployeeProjectAssignment)

### `employees`
- `id`, `internal_code` (unique `unique(organization_id, internal_code)`), `first_name`, `last_name`, `phone` nullable, `personal_id_number_encrypted` nullable (encrypted cast; visible only per permission — spec section 5: "პირადი ნომერი საჭიროების შემთხვევაში შეზღუდული წვდომით"), `photo_attachment_id` nullable FK `attachments`, `position` nullable, `profession_skills` jsonb nullable, `team_id` nullable FK `teams`, `supervisor_employee_id` nullable FK `employees`, `status` enum(`active`,`inactive`,`terminated`) default `active`, `emergency_contact_name` nullable, `emergency_contact_phone` nullable, `user_id` nullable FK `users` (nullable because an Employee may have no login account — spec section 5), soft-deletes (reference-type, but terminated employees are kept via `status`, not deletion — soft-delete reserved for true data-entry mistakes only).

### `employments`
- `id`, `employee_id` FK `employees`, `started_at` date, `ended_at` date nullable, `end_reason` nullable, `status` enum(`active`,`ended`).
- Rule: ending an employment (a) revokes the linked `users` login (`is_active=false`), (b) creates `DeviceSyncCommand` rows to revoke all active `CredentialAssignment`s on relevant devices (spec: "აუქმებს login-ს, გეგმავს ბარათის გაუქმებას"), (c) surfaces unreturned `CustodyTransaction`s (does NOT auto-create a cost deduction — explicit hard constraint). Historical attendance/financial records are never deleted when employment ends.

### `teams`
- `id`, `name`, `foreman_employee_id` nullable FK `employees`, `is_active`.

### `team_memberships`
- `id`, `team_id` FK `teams`, `employee_id` FK `employees`, `started_at`, `ended_at` nullable. Unique active membership: partial unique `unique(organization_id, employee_id) WHERE ended_at IS NULL` (an employee is on at most one team at a time — reasonable default; if the business later needs multi-team membership, this is a documented v1 simplification, logged as a decision).

### `rate_histories`
- `id`, `employee_id` FK `employees`, `project_id` nullable FK `projects` (null = the employee's base/default rate; non-null = a project override — spec section 5: "პროექტის ტარიფი → თანამშრომლის ძირითადი ტარიფი" priority), `rate_type` enum(`hourly`,`daily`), `amount` numeric(14,2), `currency` char(3) default `GEL`, `effective_from` date, `effective_to` date nullable, `change_reason`, `approved_by_user_id` FK `users`.
- **Constraint (spec section 5, hard rule)**: for a given `(organization_id, employee_id, project_id IS NULL/value, rate_type)` group, effective periods must not overlap. Enforced via a Postgres exclusion constraint using the `btree_gist` extension: `EXCLUDE USING gist (employee_id WITH =, coalesce(project_id, '00000000-0000-0000-0000-000000000000'::uuid) WITH =, rate_type WITH =, daterange(effective_from, effective_to, '[]') WITH &&)`. Application layer also validates this pre-save for a fast, friendly error, but the DB constraint is the real guarantee.
- Rate resolution logic (Domain service, not duplicated in Vue): for a given employee + work date + project, pick the project-level `rate_history` row effective on that date if one exists, else the base (`project_id IS NULL`) row effective on that date; if neither exists, **block accrual** for that date (spec: "არარსებობისას დარიცხვა დაიბლოკოს") and raise a `PayrollBlockedException`/anomaly rather than defaulting to zero or guessing.

### `employee_project_assignments`
- `id`, `employee_id` FK `employees`, `project_id` FK `projects`, `starts_on` date, `ends_on` date nullable, `assignment_type` nullable (e.g., primary/temporary).
- No overlap constraint mandated by spec text for this table (unlike rates) — assignments may legitimately overlap (an employee can be assigned to more than one project's date range at once); attendance still resolves actual worked project per session, not per assignment.

---

## Domain: Devices (Site, Device, DeviceCapability, Credential, CredentialAssignment, AccessPolicy, DeviceSyncCommand, DeviceCheckpoint)

### `sites`
- `id`, `name`, `address` nullable, `timezone` default `Asia/Tbilisi`, `is_active`.

### `devices`
- `id`, `site_id` FK `sites`, `serial_number` (unique `unique(organization_id, serial_number)`), `model` (e.g. `XPASS2-XP2-MDPB`), `firmware_version` nullable, `install_location` nullable, `reader_role` enum(`in`,`out`,`unspecified`), `device_timezone` default `Asia/Tbilisi`, `status` enum(`online`,`offline`,`degraded`,`unknown`) default `unknown`, `last_heartbeat_at` nullable, `last_event_at` nullable, `sync_status` enum(`in_sync`,`pending`,`error`) default `pending`, `connector_version` nullable.
- Explicit rule surfaced in UI (not a column, a computed/derived state): "online" never implies "all cards synced" — `sync_status` is tracked independently.

### `device_capabilities`
- `id`, `device_id` FK `devices`, `capability_key` (e.g. `max_users`, `max_cards`, `supports_mifare`, `supports_em`), `capability_value` jsonb, `read_at` timestamptz (when this capability snapshot was captured from the device — never hardcoded, always read live per spec section 6: "მოწყობილობის მეხსიერების ზუსტი ლიმიტი არ გამოიგონო — წაიკითხე capability").

### `credentials`
- `id`, `card_type` (e.g. `EM`, `MIFARE`), `canonical_identifier` (normalized decimal representation — see below), `raw_bytes` bytea nullable, `bit_length` int nullable, `leading_zeros_preserved` bool default true (spec section 6: preserve bytes/length/leading zeros; decimal/hex/byte-order conversion is a documented adapter concern, not lossy at storage), `status` enum(`unassigned`,`issued`,`lost`,`revoked`,`expired`) default `unassigned`.
- Unknown-card events (a card read that matches no `credentials` row) create a triage record (see `RawAccessEvent.unmatched_credential_ref` below) — spec explicitly forbids auto-creating an Employee from an unknown card.

### `credential_assignments`
- `id`, `credential_id` FK `credentials`, `employee_id` FK `employees`, `valid_from` timestamptz, `valid_to` timestamptz nullable, `site_scope` jsonb nullable (site/access-group list), `status` enum(`active`,`superseded`,`revoked`,`expired`).
- **Constraint (spec section 6, hard rule: "ბარათის აქტიური მინიჭება უნიკალურია")**: at most one `active` assignment per `credential_id` at a time. Enforced via partial unique index: `unique(organization_id, credential_id) WHERE status = 'active'`. Re-issuing a card creates a NEW assignment row (old one transitions to `superseded`), preserving history; historical `RawAccessEvent`s always resolve to whichever assignment was active at the event's `normalized_event_time_utc`, not the current one — resolved by a time-range query against assignment history, never by a mutable "current owner" pointer.

### `access_policies`
- `id`, `name`, `site_ids` jsonb, `schedule_definition` jsonb (allowed time windows), `credential_assignment_ids` jsonb or a pivot table `access_policy_assignments` (pivot preferred for queryability: `access_policy_id`, `credential_assignment_id`), `is_active`.

### `device_sync_commands`
- `id`, `device_id` FK `devices`, `command_type` enum(`add_user`,`update_user`,`revoke_credential`,`sync_access_group`,`sync_schedule`,...), `payload` jsonb, `idempotency_key` (unique `unique(organization_id, idempotency_key)`), `command_version` int (monotonic per `(device_id, target_entity)` — spec: "ძველმა retry-მ ახალ გაუქმებას ვერ გადააწეროს": a stale in-flight retry must never overwrite a newer command's effect; enforced by comparing `command_version` before applying), `status` enum(`pending`,`processing`,`succeeded`,`failed`,`retry`,`dead_letter`) default `pending`, `attempts` int default 0, `last_error` nullable, `acknowledged_at` nullable.
- UI shows **desired state** (this table) vs. **acknowledged state** (last successfully applied command per device+entity) separately — spec section 6 explicit requirement; an offline device's pending revocation must never render as a completed revocation.

### `device_checkpoints`
- `id`, `device_id` FK `devices`, `stream_epoch` bigint (increments on device reset/rollover), `last_native_event_id` bigint, `last_confirmed_at` timestamptz.
- Used to resume event ingestion after a network outage from a durable point, with overlapping-batch-safe dedup (see `RawAccessEvent` below) — spec section 6.

---

## Domain: Attendance (RawAccessEvent, AttendanceSession, AttendanceAnomaly, ShiftTemplate, ShiftAssignment, AttendanceAdjustment, Timesheet, TimesheetLine, Approval)

### `raw_access_events` — immutable, append-only, high volume (spec target: 10M+ rows; partition by month on `received_at` if/when Integration determines it's needed)
- `id`, `device_id` FK `devices`, `native_event_id` bigint, `stream_epoch` bigint, `raw_device_time` timestamptz, `normalized_event_time_utc` timestamptz, `received_at` timestamptz, `credential_id` nullable FK `credentials` (nullable to support unmatched-card events), `unmatched_credential_ref` nullable (raw card identifier string when no `credentials` row matches — creates a triage record, never auto-creates an Employee, per spec section 6), `event_code`, `event_subcode` nullable, `reader_direction_snapshot` enum(`in`,`out`,`unspecified`) (captured at ingestion time, not re-derived later even if `Device.reader_role` config changes afterward), `payload` jsonb, `payload_hash` (auxiliary/secondary dedup signal only — spec explicit: "Payload hash გამოიყენე დამხმარე შემოწმებად, არა ერთადერთ გასაღებად"), `ingestion_source` (e.g. `device-connector`, `biostar-import`).
- **Dedup constraint (spec section 6, hard rule)**: `unique(organization_id, device_id, native_event_id, stream_epoch)`. A device reset/native-ID rollover bumps `stream_epoch`, so old and new events with the same `native_event_id` under different epochs are correctly treated as distinct — never silently overwritten.
- No `updated_at`/`version` mutation path — genuinely append-only; corrections happen at the `AttendanceAdjustment` layer, never here.

### `attendance_sessions` — computed/derived, versioned/re-computable
- `id`, `employee_id` FK `employees`, `site_id` FK `sites`, `project_id` nullable FK `projects` (resolved project attribution for the session), `clock_in_event_id` nullable FK `raw_access_events`, `clock_out_event_id` nullable FK `raw_access_events`, `clock_in_at` timestamptz nullable, `clock_out_at` timestamptz nullable, `work_date` date (local business date per site timezone — spec: night shift attributed to the shift's start date), `raw_duration_minutes` int nullable (unrounded, exact), `payable_minutes` int nullable (after break deduction, still exact minutes — spec: "Raw დრო არასდროს დამრგვალდეს"), `reconstruction_run_id` (identifies which deterministic reconstruction pass produced this row — reconstruction must be "დეტერმინისტული, ხელახლა გაშვებადი და ერთსა და იმავე წყაროზე ერთნაირი შედეგი"; re-running against the same raw events must be idempotent/replaceable, not additive), `status` enum(`open`,`closed`,`superseded`).
- **Constraint**: overlapping sessions for the same employee are blocked (spec section 7 hard rule) — enforced via exclusion constraint: `EXCLUDE USING gist (employee_id WITH =, tstzrange(clock_in_at, coalesce(clock_out_at, 'infinity'), '[]') WITH &&) WHERE status != 'superseded'`.
- A missing clock-out never causes a full day to be silently paid (spec: "ავტომატური სრული დღის დარიცხვა არ ხდება") — instead it stays `status='open'` with `payable_minutes=null` and raises an `AttendanceAnomaly` of type `missing_out`.

### `attendance_anomalies`
- `id`, `employee_id` FK `employees`, `attendance_session_id` nullable FK `attendance_sessions`, `anomaly_type` enum(`duplicate_in`,`unknown_out`,`missing_out`,`excessive_duration`,`impossible_site_crossing`,`late_arriving_data`,`clock_drift`,`out_of_order_events`,`data_gap`), `detected_at`, `details` jsonb, `resolved_at` nullable, `resolution_note` nullable, `resolved_by_user_id` nullable.

### `shift_templates`
- `id`, `site_id` nullable FK `sites`, `name`, `starts_at_local` time, `ends_at_local` time, `crosses_midnight` bool, `scheduled_days` jsonb (e.g. `["mon","tue",...]`), `break_policy` jsonb (`{"type":"fixed"|"scheduled","minutes":60,"windows":[...]}`), `allowed_late_minutes` int default 0, `rounding_policy` jsonb (documented rule, never hardcoded — spec section 7/8: thresholds are configurable, not baked into code), `requires_approval_by_role` nullable, soft-deletes (reference data).
- Break-deduction rule: a given break window is deducted from `payable_minutes` at most once per session — enforced in the Domain reconstruction service by tracking which `(attendance_session_id, break_window_key)` pairs have already been applied (a small `attendance_session_break_deductions` join table: `attendance_session_id`, `shift_template_id`, `break_window_key`, unique on the triple) rather than re-summing a policy blindly on every recompute.

### `shift_assignments`
- `id`, `employee_id` FK `employees`, `shift_template_id` FK `shift_templates`, `effective_from` date, `effective_to` date nullable.

### `attendance_adjustments`
- `id`, `employee_id` FK `employees`, `work_date` date, `site_id` nullable FK `sites`, `corrected_clock_in_at` nullable, `corrected_clock_out_at` nullable, `corrected_hours` numeric(6,2) nullable (used when hours, not exact times, are the correction unit), `reason`, `evidence_attachment_id` nullable FK `attachments`, `requested_by_user_id`, `approved_by_user_id` nullable, `status` enum(`pending`,`approved`,`rejected`), `original_session_id` nullable FK `attendance_sessions` (reference only — the original row is never overwritten, per spec: "ორიგინალი არ გადაიწეროს").
- **Constraint**: negative duration blocked (`corrected_clock_out_at > corrected_clock_in_at`, DB check constraint) and overlap-with-other-approved-sessions blocked, mirroring the `attendance_sessions` exclusion constraint logic, checked in the approval Action inside the same transaction.
- A late-arriving raw event for an already-locked timesheet period creates an `attendance_adjustments` row flagged `for_locked_period=true` instead of mutating history (spec section 7: "Locked პერიოდში დაგვიანებული მოვლენა ქმნის adjustment request-ს; ისტორიულ ხელფასს ჩუმად არ ცვლის").

### `timesheets`
- `id`, `employee_id` FK `employees`, `pay_period_id` FK `pay_periods`, `status` enum(`draft`,`submitted`,`approved`,`locked`,`rejected`) default `draft`, `submitted_at`, `submitted_by_user_id`, `approved_at`, `approved_by_user_id`, `rejected_reason` nullable, `locked_at` nullable, `source_sessions_version_snapshot` jsonb (records exactly which `attendance_sessions` rows + their `version` were included at approval time, and which calculation-policy version was used — spec section 7: "დამტკიცებისას ინახება წყაროების ვერსია და გამოთვლის პოლიტიკა").
- Rejected returns to `draft` with `rejected_reason` populated (spec explicit).

### `timesheet_lines`
- `id`, `timesheet_id` FK `timesheets`, `work_date` date, `project_id` FK `projects`, `attendance_session_id` nullable FK `attendance_sessions`, `payable_minutes` int, `rate_type` enum(`hourly`,`daily`), `rate_snapshot_id` FK `rate_histories` (captures which rate row applied — supports mid-shift rate changes splitting into multiple lines, spec section 7 test case: "ტარიფი იცვლება შუა ცვლაში: 2 სთ × 10 + 2 სთ × 15 → ორი rate snapshot").
- **Constraint (spec section 7 hard rule)**: sum of `payable_minutes` across a day's `timesheet_lines` for one employee must not exceed that day's approved `attendance_sessions.payable_minutes` total — enforced by an Action-level aggregate check inside the approval transaction (a CHECK constraint can't easily aggregate across sibling rows in Postgres without a trigger; a `BEFORE INSERT/UPDATE` trigger or an application-level `SELECT ... FOR UPDATE` aggregate check inside the same DB transaction is used — Integration agent to confirm which was implemented in `docs/decisions.md`).

### `approvals` (generic, polymorphic — reused by Timesheet, PayRun, TaskAcceptance, CredentialAssignment changes, etc.)
- `id`, `approvable_type`, `approvable_id`, `target_version` int (the `version` of the approvable row at approval time — spec section 19: "დამტკიცების შემდეგ შეცვლილი draft ვერ ჩაითვლება ძველად დამტკიცებულად"; the approval Action re-checks the current `version` equals `target_version` before committing, else 409), `approver_user_id`, `decision` enum(`approved`,`rejected`), `reason` nullable, `decided_at`.

---

## Domain: Payroll (PayPeriod, PayRun, PayRunLine, Advance, Payment, PaymentAllocation, PayAdjustment)

### `pay_periods`
- `id`, `starts_on` date, `ends_on` date, `status` enum(`open`,`closed`), unique `unique(organization_id, starts_on, ends_on)`.

### `pay_runs`
- `id`, `pay_period_id` FK `pay_periods`, `status` enum(`draft`,`calculated`,`reviewed`,`approved`,`locked`) default `draft`, `calculated_at`, `calculated_by_user_id`, `approved_at`, `approved_by_user_id`, `policy_version_snapshot` jsonb (rounding rule, daily-policy thresholds used — spec section 8: policy is config, and the version used must be recorded).

### `pay_run_lines`
- `id`, `pay_run_id` FK `pay_runs`, `employee_id` FK `employees`, `project_id` nullable FK `projects` (a pay run line can be split per project — spec section 7/8), `basis` enum(`hourly`,`daily`), `quantity` numeric(10,2) (hours or day-units), `rate_snapshot_id` FK `rate_histories`, `formula_applied` text (human-readable, e.g. `"480 min / 60 × 15.00 GEL"`), `gross_amount` numeric(14,2), `adjustments_amount` numeric(14,2) default 0, `net_amount` numeric(14,2).
- **Constraint (spec section 8 hard rule)**: a single employee's day-unit total across all sites/projects on one calendar work-date defaults to a maximum of 1.0 day-unit unless an explicit separately-approved exception exists (`pay_run_line_exceptions` flag or a linked `Approval` row) — enforced in the Domain calculation Action, not just documented.
- Rounding: final GEL amount rounds half-up to 0.01 at the line level (documented in `docs/decisions.md` once the exact rounding helper is built); splitting one computed total across multiple project lines uses deterministic largest-remainder allocation so the lines' sum always equals the pre-split total exactly.

### `advances`
- `id`, `employee_id` FK `employees`, `amount` numeric(14,2), `currency` char(3), `granted_at`, `granted_by_user_id`, `reason`, `status` enum(`outstanding`,`fully_deducted`,`cancelled`).
- **Constraint (spec section 8 hard rule)**: an advance is deducted from a payable balance at most once — enforced via the `payment_allocations`/deduction-ledger pattern below (an advance's outstanding amount only decreases through an explicit, auditable allocation row, never a second independent "deduct advance" mutation).

### `payments`
- `id`, `employee_id` FK `employees`, `pay_run_id` nullable FK `pay_runs` (nullable — a payment can also settle a standalone advance-only balance), `paid_at`, `amount` numeric(14,2), `currency` char(3), `method` (e.g. `bank_transfer`,`cash`), `reference` nullable, `evidence_attachment_id` nullable FK `attachments`, `status` enum(`pending`,`completed`,`cancelled`) — **`pending` is never treated as `paid` in balance calculations** (spec explicit hard rule: "pending გადახდა paid არ ჩაითვლოს"). Partial payments allowed (no full-settlement constraint).
- Hard constraint reminder (from the outer task, not just the spec): this system never sends a real payment; `payments` rows are a record-keeping ledger of payments made through an external process, not a payment-execution feature.

### `payment_allocations`
- `id`, `payment_id` nullable FK `payments`, `pay_run_line_id` nullable FK `pay_run_lines`, `advance_id` nullable FK `advances`, `allocated_amount` numeric(14,2), `allocation_type` enum(`payment_to_earnings`,`advance_deduction`,`pay_adjustment`).
- Outstanding balance formula (Domain service, computed not stored): `approved pay_run_lines.net_amount − Σ(payment_allocations where allocation_type='payment_to_earnings') − Σ(payment_allocations where allocation_type='advance_deduction')`, matching spec section 8 exactly: "გასაცემი ნაშთი = დამტკიცებული ანგარიშსწორების თანხა − მასზე განაწილებული გადახდები − განაწილებული ავანსები."

### `pay_adjustments`
- `id`, `pay_run_line_id` nullable FK `pay_run_lines`, `employee_id` FK `employees`, `type` enum(`overtime`,`holiday`,`bonus`,`vacation`,`absence_deduction`,`damaged_tool_deduction`,`penalty`,`other`) (configurable category list — spec section 8), `amount` numeric(14,2) (signed), `reason` required, `approved_by_user_id` required, `requires_separate_permission` bool default true for deduction-type adjustments (spec hard rule: "დაზიანებული ხელსაწყოს, ჯარიმის ან ვალის ავტომატური ჩამოჭრა აკრძალულია" — this row type only ever gets created through an explicit human-approved action, never an automated job).
- Corrections to an already-approved/locked period are new `pay_adjustments`/reversal rows referencing the original `pay_run_line_id`, never an edit to the locked line (spec: "დამტკიცებული პერიოდის გასწორება ხდება reversal/adjustment-ით და არა delete-ით").

---

## Domain: Assets (Asset, AssetKit, AssetLocation, CustodyTransaction, CustodyLine, Acknowledgement, Maintenance, AssetIncident, Stocktake)

### `assets`
- `id`, `name`, `category`, `tracking_type` enum(`individual`,`kit_component`,`quantity`,`consumable`), `inventory_code` (unique `unique(organization_id, inventory_code)`), `initial_location_id` FK `asset_locations`, `condition` enum(`new`,`good`,`fair`,`damaged`,`under_repair`,`written_off`), `brand` nullable, `model` nullable, `serial_number` nullable, `purchased_at` nullable, `purchase_price` numeric(14,2) nullable, `purchase_currency` char(3) nullable, `supplier` nullable, `warranty_until` nullable, `manual_attachment_id` nullable, `bundle_contents` jsonb nullable, `ownership` enum(`owned`,`rented`) default `owned`, `calibration_due_at` nullable, `service_due_at` nullable, `quantity_on_hand` numeric(12,2) nullable (only meaningful for `tracking_type IN ('quantity','consumable')`; individually-tracked assets always represent quantity 1 — spec explicit), `qr_token` (opaque, unique `unique(qr_token)` — resolving it always re-runs the Policy check server-side; the token itself grants no access).

### `asset_kits`
- `id`, `kit_asset_id` FK `assets` (the kit as a trackable unit), `component_asset_id` FK `assets`, `quantity` numeric(10,2) default 1.

### `asset_locations`
- `id`, `locatable_type` (`warehouse`,`site`,`employee`,`in_transit`), `locatable_id` uuid, `asset_id` FK `assets`, `as_of` timestamptz, `is_current` bool (partial unique `unique(organization_id, asset_id) WHERE is_current = true` — an asset has exactly one current location record; history preserved via non-current rows).

### `custody_transactions` (covers issue, return, transfer — spec sections 9.2–9.4)
- `id`, `type` enum(`issue`,`return`,`transfer`), `issuing_warehouse_id` nullable FK `asset_locations`/warehouses, `receiving_employee_id` nullable FK `employees`, `receiving_warehouse_id` nullable, `project_id` nullable FK `projects`, `occurred_at`, `expected_return_at` nullable, `condition_at_transaction` enum(`new`,`good`,`fair`,`damaged`), `accessories_note` nullable, `photo_attachment_ids` jsonb, `comment` nullable, `issued_by_user_id`, `received_confirmation_user_id` nullable, `received_confirmation_at` nullable, `status` enum(`draft`,`awaiting_receipt`,`issued`,`partially_returned`,`returned`,`in_transit`) — exact status set per spec 9.2/9.4 combined.
- Digital confirmation is explicitly **not** a qualified electronic signature (spec: "ეს არ უნდა მოიხსენიო როგორც კვალიფიციური ელექტრონული ხელმოწერა") — UI copy must not claim otherwise; only `account_id` + `timestamp` are recorded as the confirmation evidence.
- Direct employee-to-employee transfer still creates a full chain: modeled as a `transfer` transaction with both `issuing` (from employee, via a location row) and `receiving_employee_id` populated, never a silent location field edit (spec: "პირდაპირი employee-to-employee გადაცემა დატოვებს სრულ custody chain-ს").

### `custody_lines`
- `id`, `custody_transaction_id` FK `custody_transactions`, `asset_id` FK `assets`, `quantity` numeric(10,2) default 1, `returned_quantity` numeric(10,2) default 0, `line_condition` nullable.
- **Constraint (spec section 9.6 hard rule — concurrent double-issue)**: issuing an asset acquires a row-level lock (`SELECT ... FOR UPDATE` on the current `asset_locations`/availability row, or a dedicated `asset_reservation` unique constraint) inside a DB transaction before creating the `custody_transaction`/`custody_lines` rows, so that of two simultaneous issue attempts on the same individually-tracked asset, only one can succeed — the spec explicitly calls for "transaction and lock/unique constraint," implemented as: a partial unique index `unique(organization_id, asset_id) WHERE status IN ('issued','awaiting_receipt')` on a lightweight `asset_active_custody` marker table (or equivalently on `asset_locations.is_current` combined with a `locked_for_issue` transaction), decided precisely by the Assets module agent and recorded in `docs/decisions.md`.
- Partial return keeps remaining obligation visible: `custody_lines.quantity - returned_quantity > 0` means still outstanding; a `damaged` return condition routes the asset's current condition to `under_repair`/quarantine rather than back to `good`/available (spec 9.3 explicit).

### `acknowledgements`
- `id`, `custody_transaction_id` FK `custody_transactions`, `acknowledged_by_user_id`, `acknowledged_at`, `role_at_time` (issuer/receiver).

### `maintenance`
- `id`, `asset_id` FK `assets`, `vendor` nullable, `scheduled_at` nullable, `completed_at` nullable, `actual_cost` numeric(14,2) nullable, `next_service_due_at` nullable, `notes` nullable.

### `asset_incidents`
- `id`, `asset_id` FK `assets`, `incident_type` enum(`damage`,`loss`,`write_off_request`), `occurred_at`, `location` nullable, `description`, `photo_attachment_ids` jsonb, `reported_by_user_id`, `estimated_repair_cost` numeric(14,2) nullable, `reviewed_by_user_id` nullable, `decision` enum(`repair`,`write_off`,`no_action`) nullable, `decided_at` nullable.
- Write-off requires an authorized approval record (`Approval` polymorphic row) and never deletes the asset — history remains (spec 9.5 explicit).

### `stocktakes`
- `id`, `scope_type` enum(`site`,`warehouse`), `scope_id`, `session_started_at`, `expected_snapshot` jsonb, `status` enum(`in_progress`,`completed`), `performed_by_user_id`.

### `stocktake_lines`
- `id`, `stocktake_id` FK `stocktakes`, `asset_id` FK `assets`, `expected_quantity` numeric(10,2), `counted_quantity` numeric(10,2) nullable, `recount_of_line_id` nullable FK `stocktake_lines` (self), `variance_approved_adjustment_id` nullable (points to whatever adjustment record actually changes the ledger — a raw scan never directly changes the accounting balance, per spec 9.6: "სკანირება პირდაპირ არ ცვლის საბუღალტრო ნაშთს").

---

## Domain: Projects & Tasks (Client, Project, ProjectLocation, WorkPackage, Task, TaskAssignee, TaskDependency, ChecklistItem, TaskSubmission, TaskAcceptance, Comment, DailyReport)

### `clients`
- `id`, `name`, `contact_info` jsonb nullable, soft-deletes.

### `projects`
- `id`, `code` (unique `unique(organization_id, code)`), `name`, `client_id` nullable FK `clients`, `manager_user_id` FK `users`, `address` nullable, `starts_on` nullable, `ends_on` nullable, `status` enum(`planning`,`active`,`on_hold`,`completed`,`cancelled`), `budget_baseline` numeric(14,2) nullable, soft-deletes.

### `project_locations` (optional WBS levels: corpus/zone → floor → space — spec: "დონეები optional")
- `id`, `project_id` FK `projects`, `parent_location_id` nullable FK `project_locations` (self, arbitrary depth so corpus/floor/space can be modeled without forcing all levels), `level_type` (e.g. `corpus`,`floor`,`space` — free-form/configurable), `name`.

### `work_packages`
- `id`, `project_id` FK `projects`, `project_location_id` nullable FK `project_locations`, `name`, `description` nullable.

### `tasks`
- `id`, `project_id` FK `projects`, `project_location_id` nullable FK `project_locations`, `work_package_id` nullable FK `work_packages`, `title`, `description` nullable, `accountable_owner_employee_id` FK `employees` (exactly one — spec: "ერთი accountable owner"), `priority`, `due_at` nullable, `planned_duration_minutes` nullable, `unit` nullable (m², m³, linear meter, piece, or configurable custom unit — stored as a string against a small `units` lookup, not a hardcoded enum, since spec says "კონფიგურირებადი სხვა ერთეულები"), `planned_quantity` numeric(12,2) nullable, `accepted_quantity` numeric(12,2) default 0, `status` enum(`draft`,`assigned`,`in_progress`,`blocked`,`submitted`,`completed`,`cancelled`), `blocked_reason` nullable, `blocked_owner_employee_id` nullable, `self_close_allowed` bool default false (spec: manager can pre-enable self-close for low-risk tasks; visible on the task and in audit), `drawing_attachment_id` nullable FK `attachments`, `drawing_revision_id` nullable FK `document_revisions` (a task binds to one specific drawing revision — uploading a newer drawing never silently re-points existing tasks, spec 10 explicit), `cancelled_reason` nullable, `reopened_reason` nullable.
- **Constraint**: `accepted_quantity ≤ planned_quantity` is a soft/business check at the TaskAcceptance step (see below), not a blind DB CHECK on `tasks` alone, since acceptance is cumulative across possibly multiple `task_submissions`.

### `task_assignees`
- `id`, `task_id` FK `tasks`, `employee_id` nullable FK `employees`, `team_id` nullable FK `teams` (either an individual or a whole brigade can be an additional performer, per spec: "დამატებითი შემსრულებლები ან ბრიგადა").

### `task_dependencies`
- `id`, `task_id` FK `tasks`, `depends_on_task_id` FK `tasks`.
- **Constraint (spec section 10 hard rule)**: no dependency cycles. Enforced at the Domain Action level with a graph-cycle check before insert (Postgres has no native cycle-prevention constraint for arbitrary-depth DAGs); a recursive CTE check (`WITH RECURSIVE`) runs inside the same transaction as the insert.

### `checklist_items`
- `id`, `task_id` FK `tasks`, `label`, `is_required` bool default true, `is_checked` bool default false, `checked_by_user_id` nullable, `checked_at` nullable.

### `task_submissions`
- `id`, `task_id` FK `tasks`, `submitted_by_employee_id` FK `employees`, `submitted_quantity` numeric(12,2) nullable, `comment` nullable, `photo_attachment_ids` jsonb, `submitted_at`, `status` enum(`pending_review`,`accepted`,`returned`) default `pending_review`, `returned_reason` nullable.
- **Constraint (spec section 10 hard rule)**: if photos are required for this task type and the upload fails, the submission must NOT transition to `submitted` — it stays recoverable as a draft (enforced at the Action level: the submission row is only created/finalized after all required attachments report `status='available'`, not `pending`/`quarantined` — see `attachments` below).
- Only the accountable owner or an authorized reviewer can close/accept; an employee can never close someone else's task (Policy-enforced, spec explicit hard rule and acceptance-test row).

### `task_acceptances`
- `id`, `task_submission_id` FK `task_submissions`, `accepted_by_user_id`, `accepted_quantity` numeric(12,2), `accepted_at`, `notes` nullable.
- **Constraint (spec section 10 hard rule)**: `accepted_quantity` for a submission ≤ that submission's `submitted_quantity`; and re-accepting/re-reviewing must not double-count already-accepted volume toward `tasks.accepted_quantity` — the running total on `tasks.accepted_quantity` is only ever incremented once per `task_acceptance` row (idempotent via a unique `task_submission_id` per acceptance and a Domain Action that recomputes the task total by summing `task_acceptances`, not by repeated `+=` mutation).

### `comments`
- `id`, `commentable_type`, `commentable_id` (polymorphic — primarily `tasks`, extendable), `author_user_id`, `body`, `mentions` jsonb (user IDs), `parent_comment_id` nullable FK `comments` (replies), `edited_at` nullable, `edit_history` jsonb nullable.

### `daily_reports` (P1 baseline — spec section 11)
- `id`, `project_id` FK `projects`, `report_date` date, `responsible_user_id`, `teams_present` jsonb, `headcount_from_attendance` int nullable (pulled from `attendance_sessions`), `headcount_manual_override` int nullable, `headcount_variance_note` nullable, `work_performed_note` text nullable, `equipment_used` jsonb nullable, `materials_received_note` text nullable, `delays_note` text nullable, `quality_safety_note` text nullable, `photo_attachment_ids` jsonb, `next_day_plan` text nullable, `weather_manual` nullable (spec: weather is manual initially, automated service optional/future), `status` enum(`draft`,`submitted`,`accepted`) default `draft`.
- Editing an already-accepted/closed day creates a `daily_report_revisions` row (append, not overwrite — spec 11 explicit: "დახურული დღის რედაქტირება ქმნის revision-ს"). The report's work-quantity figures link to `tasks.accepted_quantity` by reference (FK/joined view) rather than storing a second independent financial figure, per spec: "არა განმეორებითი ფინანსური დარიცხვით."

### `daily_report_revisions`
- `id`, `daily_report_id` FK `daily_reports`, `snapshot` jsonb, `revised_by_user_id`, `revised_at`, `reason` nullable.

---

## Cross-cutting / shared entities

### `attachments`
- `id`, `owner_type`, `owner_id` (polymorphic — task submissions, custody transactions, daily reports, employee documents, etc.; existence AND authorization of the owner are re-checked server-side on every access, per spec section 19: "დანართის polymorphic owner reference-ისას existence და authorization სერვერზე სავალდებულოა"), `disk` (private), `storage_path` (server-generated, never derived from the uploaded filename — spec section 21 explicit: "ატვირთული ფაილის სახელი storage path-ს ვერ განსაზღვრავს"), `original_filename` (display only), `mime_type`, `byte_size`, `checksum`, `status` enum(`initiated`,`uploaded`,`scanning`,`available`,`quarantined`,`failed`) (spec section 20 upload lifecycle: initiate → signed upload → finalize → validation/scan → available), `preview_path` nullable (compressed preview, per section 21), `uploaded_by_user_id`, `caption` nullable, `classification` nullable (`before`,`after`,`general` — spec 10), `taken_at_client_claimed` nullable (EXIF/client-claimed capture time — explicitly untrusted metadata; `created_at`/upload time is the trusted timestamp, spec 10 explicit).
- Size limits (configurable, spec 21 starting defaults): photo 20 MB, PDF 50 MB, max 20 files per submission — enforced in the upload-finalize Action, values sourced from config, not hardcoded inline.

### `document_revisions`
- `id`, `document_attachment_id` FK `attachments`, `project_id` nullable FK `projects`, `category` nullable, `revision_label`, `author_user_id`, `approved_at` nullable, `approved_by_user_id` nullable, `is_current` bool.
- Markup/annotations stored as a separate layer: `document_annotations` (`id`, `document_revision_id`, `author_user_id`, `annotation_data` jsonb, `created_at`) — never flattened into the original file (spec 15: "Markup/annotation შეინახე ცალკე ფენად").

### `notifications`
- `id`, `recipient_user_id`, `type` (task_assigned, tool_return_due, mention, overdue, timesheet_exception, device_fault, etc. — spec section 17 P1 list), `payload` jsonb, `dedup_key` (unique `unique(organization_id, recipient_user_id, dedup_key)` window — exact dedup window logic decided by the Notifications module agent and logged), `read_at` nullable, `deep_link` nullable, `created_at`.
- Lock-screen/preview text must never include full salary amounts or full card numbers (spec 17 explicit) — enforced by a dedicated "safe preview" formatter used wherever a notification is rendered outside an authenticated in-app context.

### `audit_events`
- `id`, `actor_user_id` nullable (nullable for system/job-initiated actions), `action`, `target_type`, `target_id`, `occurred_at`, `reason` nullable, `request_id` nullable, `before_state` jsonb nullable (masked per field-sensitivity rules), `after_state` jsonb nullable, `is_high_risk` bool default false (flags entries needing the separately-protected export/retention path, spec section 21).
- Append-only; no update/delete path exists in the app for a regular administrator (spec 21 explicit: "audit-ის რედაქტირება ჩვეულებრივი ადმინისტრატორისთვის დაუშვებელია").

### `outbox_events`
- `id`, `aggregate_type`, `aggregate_id`, `event_type`, `payload` jsonb, `occurred_in_transaction_at`, `processed_at` nullable, `status` enum(`pending`,`processed`,`failed`), `attempts` int default 0.

### `idempotency_records`
- `id`, `idempotency_key`, `endpoint_signature` (route name/method), `request_payload_hash`, `response_snapshot` jsonb, `status` enum(`in_progress`,`completed`), `expires_at`.
- Unique: `unique(organization_id, idempotency_key, endpoint_signature)`.

---

## Out of scope for this file (P2+, named for forward-reference only — see `docs/implementation-plan.md`)

- **Materials/Warehouse (P2)**: `Item`, `Unit`, `UnitConversion`, `Warehouse`, `StockMovement`, `StockMovementLine`, `StockReservation`.
- **Procurement (P2)**: `Supplier`, `PurchaseRequest`, `Quotation`, `PurchaseOrder`, `GoodsReceipt`, `SupplierInvoice`.
- **Budget/Cost (P2)**: `CostCode`, `BOQRevision`, `BOQLine`, `BudgetRevision`, `CostLedgerEntry`, `ChangeOrder`, `Expense`.
- **CRM/Quality (P2/P3)**: `Contact`, `Opportunity`, `Proposal`, `Contract`, `Inspection`, `Defect`, `SafetyIncident`, `RFI`, `Submittal`.

Do not create migrations for these during Foundation/P0/P1. They are reserved names to keep the eventual P2 schema consistent with this document's naming style when that phase starts.
