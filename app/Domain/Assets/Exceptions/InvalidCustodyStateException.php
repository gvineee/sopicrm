<?php

namespace App\Domain\Assets\Exceptions;

/**
 * Thrown for any attempted transition outside the spec section 9.2/9.4
 * state machine: draft -> awaiting_receipt -> issued ->
 * partially_returned/returned (with transfer's extra in_transit leg).
 */
class InvalidCustodyStateException extends AssetDomainException
{
    public static function forTransition(string $from, string $to): self
    {
        return new self("სტატუსის შეცვლა '{$from}'-დან '{$to}'-ზე დაუშვებელია.");
    }
}
