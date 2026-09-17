<?php

namespace App\Domain\Employees\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Domain\Employees\Actions\AcceptEmployeeInviteAction when the
 * one-time invite link is not acceptable: unknown token, already accepted,
 * revoked, or expired. Deliberately a single generic exception (the accept
 * page shows one neutral Georgian message either way) so a probing request
 * cannot distinguish "wrong token" from "expired token" from timing/response
 * shape — the same posture Laravel's own password-reset flow takes.
 */
class InvalidEmployeeInviteException extends RuntimeException {}
