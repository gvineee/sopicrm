<?php

namespace App\Domain\Devices\Exceptions;

use RuntimeException;

/**
 * Spec section 6 hard rule: "ბარათის აქტიური მინიჭება უნიკალურია კომპანიის
 * ფარგლებში" — thrown by App\Domain\Devices\Actions\IssueCredentialAction
 * when the target credential already has an active assignment. The DB-level
 * partial unique index (`credential_assignments_active_unique`, see the
 * Devices domain migration) is the real guarantee; this exception is the
 * fast, friendly application-layer check in front of it, mirroring the
 * pattern docs/data-model.md already uses for `rate_histories` overlap.
 */
class DuplicateActiveCredentialAssignmentException extends RuntimeException
{
    public static function forCredential(string $credentialId): self
    {
        return new self("Credential {$credentialId} already has an active assignment — reissue it instead of issuing again.");
    }
}
