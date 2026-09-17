<?php

namespace App\Domain\Projects\Exceptions;

/**
 * spec section 10 models the WBS as an optional, arbitrary-depth tree
 * (project -> corpus/zone -> floor -> space). `project_locations.parent_location_id`
 * is self-referencing with no DB-level constraint against cycles (Postgres
 * has none for this shape), so re-parenting an existing location under one
 * of its own descendants is checked here, in the Domain layer, before the
 * write — mirrors data-model.md's own note that `task_dependencies` cycle
 * prevention is "a Domain Action concern," applied to the same kind of
 * self-referencing tree.
 */
class ProjectLocationCycleException extends ProjectDomainException
{
    public function __construct()
    {
        parent::__construct(
            'Cannot move a WBS location under its own descendant.',
            'wbs_location_cycle',
            422,
            ['parent_location_id' => ['ლოკაციის გადატანა საკუთარი შვილობილი ლოკაციის ქვეშ დაუშვებელია.']],
        );
    }
}
