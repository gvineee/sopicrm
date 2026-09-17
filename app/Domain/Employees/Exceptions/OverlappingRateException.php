<?php

namespace App\Domain\Employees\Exceptions;

use RuntimeException;

/**
 * spec section 5 hard rule: "ერთ პერიოდში ერთი დონის ორი მოქმედი ტარიფი არ
 * უნდა გადაიკვეთოს" (within one level — base or a given project override —
 * two active rates must never overlap). Thrown by the fast, friendly
 * application-layer pre-check in
 * App\Domain\Employees\Actions\CreateRateHistoryAction BEFORE hitting the
 * database, and also thrown (wrapping the driver exception) if the
 * Postgres `rate_histories_no_overlap` GiST exclusion constraint rejects the
 * insert anyway (the real guarantee — see the Employees domain migration) —
 * so a race between two concurrent requests is still caught, just with a
 * less specific message.
 */
class OverlappingRateException extends RuntimeException
{
    public static function forPeriod(string $employeeId, ?string $projectId, string $rateType): self
    {
        $scope = $projectId === null ? 'ძირითადი ტარიფი' : "პროექტის ტარიფი ({$projectId})";

        return new self(
            "თანამშრომლის ({$employeeId}) {$scope} ({$rateType}) მითითებულ პერიოდში უკვე არსებობს მოქმედი ტარიფი — პერიოდები არ უნდა გადაიკვეთოს."
        );
    }
}
