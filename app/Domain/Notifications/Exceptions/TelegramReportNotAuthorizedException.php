<?php

namespace App\Domain\Notifications\Exceptions;

use RuntimeException;

/**
 * NOTIFY-01: thrown by App\Domain\Notifications\Actions\SendTelegramReportAction
 * when the linked user's CURRENT permissions no longer authorize the
 * requested report — before any Telegram send is attempted.
 */
class TelegramReportNotAuthorizedException extends RuntimeException {}
