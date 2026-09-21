<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Str;

/**
 * docs/architecture.md §5 "Audit" / spec sections 20-21: the ONLY supported
 * way to write an AuditEvent row — masking sensitive fields and capturing
 * actor/action/target/timestamp/reason/requestId/before-after here means
 * every caller gets that for free instead of re-implementing it per module.
 *
 * Sensitive fields are masked in the stored diff itself (not just at read
 * time) so a lower-privileged export path can never recover the real value
 * — per the hard constraint, masking must be real, not cosmetic.
 */
class AuditLogger
{
    /**
     * Field names masked wherever they appear in a before/after diff,
     * regardless of which model/table they came from — spec section 21:
     * "მგრძნობიარე ველები masked" (personal ID numbers, full card numbers,
     * salary amounts in low-privilege views, secrets/tokens).
     *
     * @var list<string>
     */
    public const MASKED_FIELDS = [
        'password',
        'password_hash',
        'personal_id_number_encrypted',
        'personal_id_number',
        'mfa_secret_encrypted',
        'mfa_secret',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'card_number',
        'remember_token',
        'token',
        'api_token',
    ];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        Model $target,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?User $actor = null,
        ?string $actorLabel = null,
        ?string $organizationId = null,
    ): AuditEvent {
        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        // Explicit override exists for the narrow case of authentication
        // events themselves (App\Domain\Auth\Listeners\LogAuthenticationEvent)
        // and pre-login invite acceptance (App\Domain\Employees\Actions\
        // AcceptEmployeeInviteAction): these happen on the SAME request that
        // establishes tenant context, before App\Http\Middleware\SetCurrentOrganization
        // has had a chance to run (it needs $request->user(), which doesn't
        // exist yet), so there's nothing in CurrentOrganization to fall back
        // to and the caller passes the target's own organization_id directly.
        //
        // withoutTenantScope() below only skips Eloquent's own WHERE clause —
        // it does nothing to Postgres's row-level security, which is enforced
        // independently at the database level against the `app.current_org_id`
        // session GUC that SetCurrentOrganization normally sets. Without the
        // middleware having run yet, that GUC is still unset/stale here, so
        // the INSERT would violate RLS even though `organization_id` on the
        // row itself is correct. Set the GUC explicitly in this override case
        // so both enforcement layers agree — same pattern already used by
        // Database\Seeders\DatabaseSeeder.
        if ($organizationId !== null && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.current_org_id', ?, false)", [$organizationId]);
        }

        return AuditEvent::withoutTenantScope()->create([
            'organization_id' => $organizationId ?? CurrentOrganization::requireId(),
            'actor_user_id' => $actor?->getKey(),
            'actor_label' => $actorLabel ?? $actor?->email,
            'action' => $action,
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'reason' => $reason,
            'request_id' => $this->currentRequestId(),
            'ip_address' => RequestFacade::ip(),
            'before' => $before !== null ? $this->mask($before) : null,
            'after' => $after !== null ? $this->mask($after) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * A model's dirty changes as a ready-made before/after pair, for the
     * common "log an update" case.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function diffOf(Model $model): array
    {
        $after = $model->getChanges();
        $before = [];

        foreach (array_keys($after) as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        return [$before, $after];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function mask(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if (in_array($key, self::MASKED_FIELDS, true)) {
                $fields[$key] = $value === null ? null : '***masked***';
            }
        }

        return $fields;
    }

    private function currentRequestId(): string
    {
        $request = RequestFacade::instance();

        /** @var string|null $id */
        $id = $request->attributes->get('request_id');

        return $id ?? (string) Str::uuid7();
    }
}
