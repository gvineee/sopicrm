<?php

namespace App\Console\Commands;

use App\Domain\Auth\Models\Organization;
use Illuminate\Console\Command;

/**
 * spec sections 20/21: "webhook/connector ingestion მხოლოდ ცალკე machine
 * identity-ით" — the ONLY supported way to mint a Sanctum token for a
 * machine client (services/device-connector, or a future server-to-server
 * integration). Never issued through a web/API endpoint: an operator runs
 * this once, out-of-band, and configures the resulting token into that
 * service's own secret store (never committed to the repo — hard
 * constraint on secrets).
 *
 * The token is bound to exactly one organization at issue time
 * (`personal_access_tokens.organization_id`), which
 * App\Http\Middleware\SetCurrentOrganization trusts for every request that
 * token authenticates — this is what makes "the authenticated token's
 * bound organization for machine clients" (docs/architecture.md §4) a real,
 * enforced fact rather than a convention.
 */
class IssueMachineToken extends Command
{
    protected $signature = 'tokens:issue-machine
        {organization : Organization UUID this token is bound to}
        {name : Human-readable identifier, e.g. "device-connector-site-1"}
        {--ability=* : Sanctum abilities, e.g. device-connector:events.write}';

    protected $description = 'Issue a scoped Sanctum personal access token for a machine/API identity, bound to one organization.';

    public function handle(): int
    {
        $organization = Organization::query()->find($this->argument('organization'));

        if ($organization === null) {
            $this->error('No such organization.');

            return self::FAILURE;
        }

        $abilities = $this->option('ability') ?: ['*'];

        $result = $organization->createToken($this->argument('name'), $abilities);

        // organization_id is stored on the token row directly (added by
        // 2026_09_16_090105_add_organization_id_to_personal_access_tokens_table.php)
        // rather than relied upon implicitly via tokenable_id, so it survives
        // even if a future migration changes what machine tokens attach to.
        $result->accessToken->forceFill(['organization_id' => $organization->id])->save();

        $this->info('Machine token issued. Store it now — it will not be shown again:');
        $this->line($result->plainTextToken);

        return self::SUCCESS;
    }
}
