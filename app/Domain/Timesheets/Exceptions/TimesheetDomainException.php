<?php

namespace App\Domain\Timesheets\Exceptions;

use RuntimeException;

/**
 * Base class for every domain-rule violation this module raises. Carries
 * enough shape for both transports to render the spec section 20 error
 * envelope ("ერთიანი error ფორმა: code, message, fieldErrors, requestId")
 * without each controller re-deriving it — see
 * App\Http\Controllers\Api\V1\Timesheets\* for the JSON rendering and
 * App\Http\Controllers\Timesheets\* for the Inertia (redirect-back-with-
 * errors) rendering of the same exception.
 *
 * No global exception-envelope normalizer exists yet anywhere in this
 * codebase (that is Foundation/Integration scope, not this module's) — see
 * docs/decisions.md's Timesheets-module entry. This class is a local,
 * self-contained implementation of the documented shape for this module's
 * own endpoints only.
 *
 * @property-read array<string, list<string>> $fieldErrors
 */
abstract class TimesheetDomainException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fieldErrors
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 422,
        private readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
