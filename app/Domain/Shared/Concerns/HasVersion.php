<?php

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * docs/architecture.md §5 (DEC-013): "every table has an integer version
 * column, incremented on update via an Eloquent saving hook." Used by
 * approval-type actions to detect a stale target (spec section 19: "ყველა
 * approval ინახავს target version-ს: დამტკიცების შემდეგ შეცვლილი draft
 * ვერ ჩაითვლება ძველად დამტკიცებულად") — callers compare the version they
 * read against the current one and reject with 409 on mismatch; this trait
 * only owns incrementing it.
 */
trait HasVersion
{
    public static function bootHasVersion(): void
    {
        static::saving(function (Model $model): void {
            if ($model->exists && $model->isDirty() && ! $model->isDirty('version')) {
                $model->setAttribute('version', ((int) $model->getOriginal('version', 0)) + 1);
            }
        });
    }
}
