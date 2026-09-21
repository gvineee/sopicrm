<?php

namespace App\Domain\Devices\Exceptions;

use RuntimeException;

/**
 * BIO-02: only `external_type === 'card'` has a confirm action wired today
 * (App\Domain\Devices\Actions\ConfirmExternalIdentifierMappingAction) — a
 * 'device'/'user' row can be recorded and viewed in triage, but confirming
 * it is a deferred next slice (see docs/claude-overnight-progress.md), not
 * silently treated as a no-op or guessed at here.
 */
class UnsupportedExternalIdentifierTypeException extends RuntimeException
{
    public static function forType(string $type): self
    {
        return new self("Confirming a '{$type}' external identifier mapping is not implemented yet — only 'card' is supported.");
    }
}
