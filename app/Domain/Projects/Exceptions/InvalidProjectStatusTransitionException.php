<?php

namespace App\Domain\Projects\Exceptions;

/**
 * Raised by ProjectStatusTransitionService when a requested status change
 * is not a legal edge in the Project status state machine (see that
 * service's docblock for the full graph). Kept distinct from the Task
 * status lifecycle in spec section 10 (draft/assigned/in_progress/blocked/
 * submitted/completed/cancelled) which belongs to the Tasks module, not
 * this one — see docs/decisions.md for the scope boundary.
 */
class InvalidProjectStatusTransitionException extends ProjectDomainException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            "Cannot transition project status from '{$from}' to '{$to}'.",
            'invalid_status_transition',
            422,
            ['status' => ["სტატუსის შეცვლა '{$from}'-დან '{$to}'-ზე დაუშვებელია."]],
        );
    }
}
