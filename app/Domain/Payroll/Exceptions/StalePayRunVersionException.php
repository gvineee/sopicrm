<?php

namespace App\Domain\Payroll\Exceptions;

/**
 * spec section 19: "დამტკიცების შემდეგ შეცვლილი draft ვერ ჩაითვლება ძველად
 * დამტკიცებულად" — review/approve/lock re-check the PayRun's current
 * `version` against the `target_version` the caller last read before
 * committing the transition, rejecting as 409 on mismatch rather than
 * silently acting on a target that changed underneath the caller (e.g. a
 * recalculation that ran between the caller loading the page and clicking
 * "approve").
 */
class StalePayRunVersionException extends PayrollDomainException
{
    public function __construct(int $expected, int $actual)
    {
        parent::__construct(
            "Expected PayRun version {$expected} but it is now at version {$actual}.",
            'stale_version',
            409,
            ['version' => ["დარიცხვის რიგი შეიცვალა წაკითხვის შემდეგ (მოსალოდნელი ვერსია {$expected}, ამჟამინდელი {$actual}). განაახლეთ გვერდი."]],
        );
    }
}
