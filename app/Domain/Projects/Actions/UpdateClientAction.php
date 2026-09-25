<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\Client;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * Audit A24. CreateClientAction existed; nothing could ever correct a client
 * afterwards, because no update path was ever built and no screen listed
 * clients at all.
 *
 * Kept deliberately small, like its create counterpart: this module needs a
 * name and contact details to attach to a project. Full client relationship
 * management (contracts, pipeline) is spec section 14 and is not this.
 */
class UpdateClientAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>|null  $contactInfo
     */
    public function execute(Client $client, string $name, ?array $contactInfo, User $actor): Client
    {
        $before = $client->only(['name', 'contact_info']);

        $client->name = $name;
        $client->contact_info = $contactInfo;
        $client->save();

        $this->auditLogger->log(
            action: 'projects.client.updated',
            target: $client,
            before: $before,
            after: $client->only(['name', 'contact_info']),
            actor: $actor,
        );

        return $client;
    }
}
