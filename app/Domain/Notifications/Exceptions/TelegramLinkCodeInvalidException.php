<?php

namespace App\Domain\Notifications\Exceptions;

use RuntimeException;

/**
 * NOTIFY-01: thrown when completing a Telegram link with an unknown,
 * already-consumed, or expired code — never reveals which of the three
 * reasons applies (a distinguishable error would let an attacker probe for
 * valid-but-expired codes).
 */
class TelegramLinkCodeInvalidException extends RuntimeException {}
