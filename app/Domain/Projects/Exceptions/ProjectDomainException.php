<?php

namespace App\Domain\Projects\Exceptions;

use RuntimeException;

/**
 * Base class for every domain-rule violation this module raises. Mirrors
 * App\Domain\Timesheets\Exceptions\TimesheetDomainException's shape (spec
 * section 20's "ერთიანი error ფორმა: code, message, fieldErrors, requestId")
 * — no global exception-envelope normalizer exists yet anywhere in this
 * codebase (Foundation/Integration scope, not this module's), so this is a
 * local, self-contained implementation of the documented shape for this
 * module's own endpoints, following the Timesheets module's own precedent.
 *
 * @property-read array<string, list<string>> $fieldErrors
 */
abstract class ProjectDomainException extends RuntimeException
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
