<?php

/**
 * Attendance module's own config (docs/architecture.md §2:
 * `config/modules/<module>.php`, owned exclusively by this module).
 *
 * Every threshold below is a routine technical decision (logged in
 * docs/decisions.md), never a fabricated payroll/business policy — spec
 * section 7 explicitly leaves these as configurable, not hardcoded
 * ("ზღვარი არ ჩაიკეროს კოდში" applies just as much here as it does to the
 * payroll daily-policy threshold spec 8 names by that exact phrase). Each is
 * overridable per-deployment via its env var without a code change, and
 * every one is read through this file — never inlined as a magic number in
 * app/Domain/Attendance.
 */
return [
    /**
     * A single session's raw duration beyond this is flagged as the
     * `excessive_duration` anomaly (spec section 7) rather than silently
     * accepted. Default 16 hours: long enough to cover a legitimate double
     * shift, short enough to catch a genuinely missed/duplicate badge.
     */
    'excessive_duration_minutes' => (int) env('ATTENDANCE_EXCESSIVE_DURATION_MINUTES', 960),

    /**
     * If a raw event's `received_at` is later than its own
     * `normalized_event_time_utc` by more than this, it is flagged
     * `late_arriving_data` (spec: "დაგვიანებული მონაცემი ქმნის
     * გამონაკლისს") — the event genuinely happened a while before the
     * server learned about it (e.g. an offline device buffering reads until
     * reconnect), as distinct from a device clock fault (see
     * `clock_drift_tolerance_minutes` below).
     */
    'late_arrival_threshold_minutes' => (int) env('ATTENDANCE_LATE_ARRIVAL_THRESHOLD_MINUTES', 15),

    /**
     * If a raw event's `normalized_event_time_utc` is AHEAD of its own
     * `received_at` by more than this (the event claims to have happened
     * after the server received it), that is a device/controller clock
     * fault, not late-arriving data — flagged `clock_drift`. A small
     * positive tolerance absorbs ordinary network/processing latency.
     */
    'clock_drift_tolerance_minutes' => (int) env('ATTENDANCE_CLOCK_DRIFT_TOLERANCE_MINUTES', 2),

    /**
     * A session left open (no matching OUT) is only worth flagging
     * `missing_out` once its work_date is clearly in the past relative to
     * the reconstruction run's "as of" instant — an employee still
     * genuinely on shift right now is not an anomaly yet. Default: the
     * calendar day must have fully elapsed (in the session's site
     * timezone) before `missing_out` fires.
     */
    'missing_out_grace_hours' => (int) env('ATTENDANCE_MISSING_OUT_GRACE_HOURS', 4),
];
