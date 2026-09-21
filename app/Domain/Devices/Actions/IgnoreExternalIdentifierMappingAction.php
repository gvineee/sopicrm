<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Exceptions\ExternalIdentifierMappingAlreadyResolvedException;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * BIO-02: dismisses a triage row that will never map to a CRM entity (a
 * visitor badge, a test swipe, a card that was returned unused). Leaves the
 * row itself intact — an admin can still see it under "resolved" — never
 * deletes it, so the triage list's own history stays auditable.
 */
class IgnoreExternalIdentifierMappingAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ExternalIdentifierMapping $mapping, ?string $note, User $actor): ExternalIdentifierMapping
    {
        if ($mapping->status !== 'pending') {
            throw ExternalIdentifierMappingAlreadyResolvedException::forMapping($mapping->id, $mapping->status);
        }

        $mapping->update([
            'status' => 'ignored',
            'note' => $note,
            'confirmed_by_user_id' => $actor->id,
            'confirmed_at' => now(),
        ]);

        $this->audit->log(
            action: 'devices.external_mapping.ignored',
            target: $mapping,
            after: ['note' => $note],
            actor: $actor,
        );

        return $mapping->refresh();
    }
}
