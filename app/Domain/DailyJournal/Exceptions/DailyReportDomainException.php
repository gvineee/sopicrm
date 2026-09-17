<?php

namespace App\Domain\DailyJournal\Exceptions;

use RuntimeException;

/**
 * Base type for every Daily Journal domain exception, so a controller can
 * catch this one class and translate to the right HTTP status/Georgian
 * message without enumerating every concrete subclass.
 */
abstract class DailyReportDomainException extends RuntimeException {}
