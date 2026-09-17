<?php

/**
 * Employees module's own config (docs/architecture.md §2:
 * `config/modules/<module>.php`, owned exclusively by that module).
 *
 * `invite_ttl_days`: spec section 5 requires HR-issued login invite links to
 * be "ვადიანი" (time-limited) but does not name a duration — this is a
 * routine technical decision (logged in docs/decisions.md), not a
 * fabricated business policy, and is safely overridable per-deployment via
 * the env var without a code change.
 */
return [
    'invite_ttl_days' => (int) env('EMPLOYEES_INVITE_TTL_DAYS', 7),
];
