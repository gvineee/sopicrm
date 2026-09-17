<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\Client;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * Quick-add for the client dropdown on the project create/edit form (spec
 * section 10: project carries a `client`). Full client relationship
 * management (contracts, sales pipeline) is spec section 14 (P3, real CRM)
 * — out of scope here; this module only needs a name to attach to a
 * project.
 */
class CreateClientAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>|null  $contactInfo
     */
    public function execute(string $name, ?array $contactInfo, User $actor): Client
    {
        $client = Client::create([
            'name' => $name,
            'contact_info' => $contactInfo,
        ]);

        $this->auditLogger->log(
            action: 'projects.client.created',
            target: $client,
            after: $client->only(['name', 'contact_info']),
            actor: $actor,
        );

        return $client;
    }
}
