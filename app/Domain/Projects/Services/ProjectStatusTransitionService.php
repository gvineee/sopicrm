<?php

namespace App\Domain\Projects\Services;

use App\Domain\Projects\Exceptions\InvalidProjectStatusTransitionException;

/**
 * Project status state machine (docs/data-model.md "projects":
 * planning/active/on_hold/completed/cancelled). Distinct from the Task
 * status lifecycle spec section 10 describes in detail (draft -> assigned
 * -> in_progress -> submitted -> completed, with blocked/cancelled/reopened
 * side-states) — that lifecycle belongs to the Tasks module's own `tasks`
 * table and Policy, not this one. This service only governs the coarse
 * project-level status field this module owns.
 *
 * Graph (routine technical decision, docs/decisions.md):
 *   planning  -> active, cancelled
 *   active    -> on_hold, completed, cancelled
 *   on_hold   -> active, cancelled
 *   completed -> active   (reopen; requires a reason, spec's general
 *                          "cancelled/reopened მოითხოვს მიზეზს" principle
 *                          for task-like lifecycles, applied here too)
 *   cancelled -> planning (reopen; requires a reason)
 *
 * `completed` and `cancelled` are otherwise terminal. Every transition is
 * written through TransitionProjectStatusAction, which also writes an
 * AuditEvent — so "დასრულების ისტორია ინახება" (completion history is
 * kept) is satisfied via the audit trail rather than a bespoke history
 * table.
 */
class ProjectStatusTransitionService
{
    /**
     * @var array<string, list<string>>
     */
    private const GRAPH = [
        'planning' => ['active', 'cancelled'],
        'active' => ['on_hold', 'completed', 'cancelled'],
        'on_hold' => ['active', 'cancelled'],
        'completed' => ['active'],
        'cancelled' => ['planning'],
    ];

    /**
     * Statuses whose transition requires a non-empty reason, mirroring
     * spec section 10's "Cancelled და reopened მოითხოვს მიზეზს" rule.
     *
     * @var list<string>
     */
    public const REASON_REQUIRED_TARGETS = ['cancelled', 'active_from_terminal'];

    public function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, self::GRAPH[$from] ?? [], true);
    }

    public function assertCanTransition(string $from, string $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidProjectStatusTransitionException($from, $to);
        }
    }

    public function requiresReason(string $from, string $to): bool
    {
        if ($to === 'cancelled') {
            return true;
        }

        // Reopening out of a terminal state is a "reopened" event.
        return in_array($from, ['completed', 'cancelled'], true);
    }

    /**
     * @return list<string>
     */
    public function allowedTargets(string $from): array
    {
        return self::GRAPH[$from] ?? [];
    }
}
