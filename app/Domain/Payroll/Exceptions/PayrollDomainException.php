<?php

namespace App\Domain\Payroll\Exceptions;

use RuntimeException;

/**
 * Base class for every domain-rule violation this module raises. Mirrors
 * App\Domain\Timesheets\Exceptions\TimesheetDomainException's shape (spec
 * section 20's error envelope: code, message, fieldErrors, requestId) so
 * both modules render errors the same way; no shared cross-module base
 * class exists yet (Foundation/Integration scope), so this is Payroll's own
 * self-contained copy of that documented shape, not a fork of behaviour.
 *
 * @property-read array<string, list<string>> $fieldErrors
 */
abstract class PayrollDomainException extends RuntimeException
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
