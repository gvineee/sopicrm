<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Support\OrganizationDatabaseContext;
use App\Domain\Auth\Support\OrganizationPurgePlanner;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hard-deletes an organization and every row that belongs to it, in one
 * transaction. Irreversible by design: this is for removing test/demo
 * tenants, not for offboarding a customer whose history must be kept.
 *
 * Which tables and in what order comes from OrganizationPurgePlanner (schema
 * introspection, children before parents). On top of that plan, three tables
 * are selected differently because their ownership is not expressed as an
 * organization_id foreign key:
 *  - `personal_access_tokens`: its organization_id is nullable; a token is
 *    also the organization's when its tokenable is the organization itself or
 *    one of the organization's users;
 *  - `sessions`: no foreign key at all, owned through `user_id`;
 *  - `password_reset_tokens`: keyed by e-mail, removed for the purged users'
 *    addresses unless another organization's user has the same address.
 *
 * A user of ANOTHER organization whose `current_organization_id` points at
 * the purged one is switched back to their own home organization rather
 * than deleted or left blocking the delete.
 *
 * The audit record is written after the purge, under the acting user's own
 * organization, inside the same transaction — so it cannot be swept away by
 * the purge it describes, and it cannot exist for a purge that rolled back.
 * Stored files (attachments on the private disk) are not removed here.
 */
class PurgeOrganizationAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly OrganizationPurgePlanner $planner,
    ) {}

    /**
     * @return array<string, int> rows removed per table (counted before deleting, so rows a CASCADE took count under their own table)
     */
    public function execute(Organization $organization, User $actor, string $confirmationName): array
    {
        if (! $actor->is_platform_admin) {
            throw new AuthorizationException('Only a platform admin may delete an organization.');
        }

        if ($confirmationName !== $organization->name) {
            throw ValidationException::withMessages([
                'confirmation_name' => 'დასადასტურებლად ორგანიზაციის დასახელება ზუსტად უნდა ჩაიწეროს.',
            ]);
        }

        $actorOrganizationIds = array_filter([$actor->organization_id, $actor->current_organization_id, CurrentOrganization::id()]);

        if (in_array($organization->id, $actorOrganizationIds, true)) {
            throw ValidationException::withMessages([
                'organization' => 'საკუთარი ორგანიზაციის წაშლა შეუძლებელია.',
            ]);
        }

        $auditOrganizationId = CurrentOrganization::id() ?? $actor->organization_id;
        $plan = $this->planner->plan();
        $organizationId = $organization->id;
        $snapshot = $organization->only(['id', 'name', 'legal_name', 'created_at']);

        try {
            $counts = OrganizationDatabaseContext::run($organizationId, fn (): array => DB::transaction(function () use ($plan, $organizationId, $organization, $actor, $auditOrganizationId, $snapshot): array {
                $this->detachOtherOrganizationsUsers($organizationId);

                $counts = [];

                foreach ($this->specialTables($organizationId) as $table => $query) {
                    $counts[$table] = $query->count();
                }

                foreach ($plan['order'] as $table) {
                    if (! array_key_exists($table, $counts)) {
                        $counts[$table] = $this->ownedRows($table, $organizationId, $plan)->count();
                    }
                }

                // Rows that hang off a user (sessions, tokens) go before the
                // users themselves; everything else follows the plan.
                foreach ($this->specialTables($organizationId) as $query) {
                    $query->delete();
                }

                foreach ($plan['order'] as $table) {
                    if (! in_array($table, ['personal_access_tokens'], true)) {
                        $this->ownedRows($table, $organizationId, $plan)->delete();
                    }
                }

                DB::table('organizations')->where('id', $organizationId)->delete();
                $counts['organizations'] = 1;

                $counts = array_filter($counts, fn (int $count): bool => $count > 0);
                ksort($counts);

                // Written under the actor's own organization (AuditLogger
                // points app.current_org_id there itself), never the purged one.
                $this->auditLogger->log(
                    action: 'platform.organization.purged',
                    target: $organization,
                    before: $snapshot,
                    after: ['rows_deleted' => $counts, 'rows_deleted_total' => array_sum($counts)],
                    reason: 'ორგანიზაცია სრულად წაიშალა პლატფორმის ადმინისტრატორის მიერ.',
                    actor: $actor,
                    organizationId: $auditOrganizationId,
                );

                return $counts;
            }));
        } catch (QueryException $e) {
            report($e);

            throw ValidationException::withMessages([
                'organization' => 'ორგანიზაციის წაშლა ვერ მოხერხდა — მის ჩანაწერებს სხვა ორგანიზაციის მონაცემები ეყრდნობა. არაფერი წაშლილა.',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $counts;
    }

    /**
     * @param  array{order: list<string>, parents: array<string, list<array{columns: list<string>, table: string, foreign_columns: list<string>}>>, organization_columns: array<string, string>}  $plan
     */
    private function ownedRows(string $table, string $organizationId, array $plan): Builder
    {
        $query = DB::table($table);

        if (Schema::hasColumn($table, 'organization_id')) {
            return $query->where("{$table}.organization_id", $organizationId);
        }

        return $query->where(function (Builder $where) use ($table, $organizationId, $plan): void {
            if (isset($plan['organization_columns'][$table])) {
                $where->orWhere("{$table}.{$plan['organization_columns'][$table]}", $organizationId);
            }

            foreach ($plan['parents'][$table] ?? [] as $reference) {
                $parentRows = $this->ownedRows($reference['table'], $organizationId, $plan);

                if (count($reference['columns']) === 1) {
                    $where->orWhereIn(
                        "{$table}.{$reference['columns'][0]}",
                        $parentRows->select("{$reference['table']}.{$reference['foreign_columns'][0]}"),
                    );

                    continue;
                }

                // Composite reference: correlated EXISTS on every column pair.
                $where->orWhereExists(function (Builder $exists) use ($table, $reference, $parentRows): void {
                    $exists->fromSub($parentRows, 'owned_parent')->selectRaw('1');

                    foreach ($reference['columns'] as $index => $column) {
                        $exists->whereColumn(
                            "owned_parent.{$reference['foreign_columns'][$index]}",
                            "{$table}.{$column}",
                        );
                    }
                });
            }
        });
    }

    /**
     * @return array<string, Builder>
     */
    private function specialTables(string $organizationId): array
    {
        $userIds = DB::table('users')->where('organization_id', $organizationId)->select('id');
        $queries = [];

        if (Schema::hasTable('personal_access_tokens')) {
            $queries['personal_access_tokens'] = DB::table('personal_access_tokens')
                ->where(function (Builder $where) use ($organizationId, $userIds): void {
                    $where->where('organization_id', $organizationId)
                        ->orWhere(fn (Builder $q) => $q
                            ->where('tokenable_type', (new Organization)->getMorphClass())
                            ->where('tokenable_id', $organizationId))
                        ->orWhere(fn (Builder $q) => $q
                            ->where('tokenable_type', (new User)->getMorphClass())
                            ->whereIn('tokenable_id', $userIds));
                });
        }

        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
            $queries['sessions'] = DB::table('sessions')->whereIn('user_id', $userIds);
        }

        if (Schema::hasTable('password_reset_tokens')) {
            $queries['password_reset_tokens'] = DB::table('password_reset_tokens')
                ->whereIn('email', DB::table('users')->where('organization_id', $organizationId)->select('email'))
                ->whereNotIn('email', DB::table('users')->where('organization_id', '!=', $organizationId)->select('email'));
        }

        return $queries;
    }

    /**
     * users.current_organization_id -> organizations is NO ACTION: a user of
     * another organization who last switched into this one would otherwise
     * block the final delete. Send them back to their own organization.
     */
    private function detachOtherOrganizationsUsers(string $organizationId): void
    {
        DB::table('users')
            ->where('current_organization_id', $organizationId)
            ->where('organization_id', '!=', $organizationId)
            ->update(['current_organization_id' => DB::raw('organization_id')]);
    }
}
