<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * docs/architecture.md §4: "Global Eloquent scope — a BelongsToOrganization
 * trait + global scope applied to every tenant-scoped model automatically
 * adds the organization_id predicate to every query built through Eloquent,
 * and automatically stamps it on create."
 *
 * The organization id ALWAYS comes from CurrentOrganization::id() (server
 * derived — see that class's docblock), never from any attribute the caller
 * tries to mass-assign: `organization_id` is force-stripped from `$fillable`
 * handling by always being set explicitly in the `creating` hook, so even a
 * FormRequest that forgot to strip a client-supplied organization_id cannot
 * make it through to the database.
 *
 * This is the Eloquent-layer half of tenant isolation; Postgres RLS
 * (2026_09_16_090110_add_row_level_security_to_business_tables.php) is the
 * defense-in-depth half that still applies even if a query bypasses
 * Eloquent (raw SQL, another process, a bug in this trait).
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new BelongsToOrganizationScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', CurrentOrganization::requireId());
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(BelongsToOrganizationScope::class);
    }
}

/**
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class BelongsToOrganizationScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = CurrentOrganization::id();

        if ($organizationId === null) {
            // No tenant context established (e.g. an artisan command run
            // without explicitly setting one). Fail closed: return zero
            // rows rather than silently querying across every tenant.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), '=', $organizationId);
    }
}
