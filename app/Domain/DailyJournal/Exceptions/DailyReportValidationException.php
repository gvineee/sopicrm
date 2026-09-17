<?php

namespace App\Domain\DailyJournal\Exceptions;

/**
 * Business-rule validation that goes beyond FormRequest field-shape checks
 * (e.g. "headcount differs from attendance but no variance note was given")
 * — kept in the Domain layer per spec section 18 ("ბიზნესწესები Laravel
 * domain services/actions-ში, არა Vue კომპონენტებში"), not duplicated
 * ad hoc in a FormRequest's `rules()`.
 *
 * @param  array<string, list<string>>  $fieldErrors
 */
class DailyReportValidationException extends DailyReportDomainException
{
    /**
     * @param  array<string, list<string>>  $fieldErrors
     */
    public function __construct(public readonly array $fieldErrors)
    {
        parent::__construct('ჟურნალის ჩანაწერი წარსადგენად არასრულია.');
    }
}
