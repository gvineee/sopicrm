<?php

namespace App\Domain\Devices\Exceptions;

use RuntimeException;

/**
 * BIO-02: thrown when confirming/ignoring a mapping that has already left
 * the `pending` state — a triage row is resolved exactly once, never
 * re-decided silently by a second concurrent reviewer.
 */
class ExternalIdentifierMappingAlreadyResolvedException extends RuntimeException
{
    public static function forMapping(string $mappingId, string $status): self
    {
        return new self("External identifier mapping {$mappingId} is already {$status} — it can no longer be confirmed or ignored.");
    }
}
