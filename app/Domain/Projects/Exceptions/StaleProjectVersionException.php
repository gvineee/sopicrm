<?php

namespace App\Domain\Projects\Exceptions;

/**
 * docs/architecture.md §5 (DEC-013) / spec section 4: "მრავალმომხმარებლიან
 * რედაქტირებაზე version conflict და გასაგები აღდგენის გზა." Every write
 * that accepts a caller-supplied `version` (project update, status change)
 * compares it against the row's current version and rejects as 409 rather
 * than silently overwriting a concurrent edit.
 */
class StaleProjectVersionException extends ProjectDomainException
{
    public function __construct(int $expected, int $actual)
    {
        parent::__construct(
            "Expected version {$expected} but the record is now at version {$actual}.",
            'stale_version',
            409,
            ['version' => ["ჩანაწერი შეიცვალა თქვენს მიერ წაკითხვის შემდეგ (მოსალოდნელი ვერსია {$expected}, ამჟამინდელი {$actual}). განაახლეთ გვერდი და სცადეთ ხელახლა."]],
        );
    }
}
