<?php

namespace App\Domain\Devices\Support;

/**
 * BioStar 2's numeric event codes, translated into the semantic taxonomy the
 * attendance side reads.
 *
 * Until now the connector sent `biostar:4102` through unmapped, with a comment
 * saying a docs-confirmed mapping pass would come later. The consequence was
 * not merely untidy. `App\Domain\Attendance\Actions\ReconstructAttendanceSessionsAction`
 * excludes exactly one code — `access_denied` — when rebuilding a worked day.
 * A `biostar:6401 ACCESS_DENIED_ACCESS_GROUP` is a badge the door REFUSED, and
 * under the old passthrough it did not match that exclusion, so a refused
 * read could be counted as an arrival. Somebody who was turned away at the
 * gate would have been paid for the day.
 *
 * The families below were read from the live server's own `/api/event_types`
 * (336 types on this install), not guessed from documentation:
 *
 *  - 4096–4143  VERIFY_SUCCESS_*     — credential presented and accepted
 *  - 4864–4879  IDENTIFY_SUCCESS_*   — identified without an ID being typed
 *  - 5632–5647  DUAL_AUTH_SUCCESS_*  — two-person rule satisfied
 *  - 4352–4367  VERIFY_FAIL_*        — credential presented and rejected
 *  - 5120–5135  IDENTIFY_FAIL_*
 *  - 5888–5903  DUAL_AUTH_FAIL_*
 *  - 6144–6159  AUTH_FAILED_* / ACCESS_DENIED_LOCKED
 *  - 6400–6431  ACCESS_DENIED_*      — recognised, and refused anyway
 *
 * Anything outside those families is `other`: device restarts, tamper, door
 * lock/unlock, enrolment, license and sync events. They are still imported —
 * the raw log stays complete — but they say nothing about a person passing a
 * door, and attendance must not read them as if they did.
 *
 * Ranges rather than a list of 336 literals because these are contiguous SDK
 * constant blocks; a range keeps a variant this install happens not to have
 * (a face reader's codes, say) classified correctly instead of falling
 * through to `other` and being silently ignored.
 */
final class BiostarEventTaxonomy
{
    public const GRANTED = 'access_granted';

    /** The exact string ReconstructAttendanceSessionsAction excludes. */
    public const DENIED = 'access_denied';

    public const OTHER = 'other';

    /** @var list<array{0: int, 1: int}> */
    private const GRANTED_RANGES = [
        [4096, 4143],   // VERIFY_SUCCESS_*
        [4864, 4879],   // IDENTIFY_SUCCESS_*
        [5632, 5647],   // DUAL_AUTH_SUCCESS_*
    ];

    /** @var list<array{0: int, 1: int}> */
    private const DENIED_RANGES = [
        [4352, 4367],   // VERIFY_FAIL_*
        [5120, 5135],   // IDENTIFY_FAIL_*
        [5888, 5903],   // DUAL_AUTH_FAIL_*
        [6144, 6159],   // AUTH_FAILED_*, ACCESS_DENIED_LOCKED
        [6400, 6431],   // ACCESS_DENIED_*
    ];

    public static function classify(int|string|null $code): string
    {
        if ($code === null || ! is_numeric($code)) {
            return self::OTHER;
        }

        $code = (int) $code;

        foreach (self::GRANTED_RANGES as [$from, $to]) {
            if ($code >= $from && $code <= $to) {
                return self::GRANTED;
            }
        }

        foreach (self::DENIED_RANGES as [$from, $to]) {
            if ($code >= $from && $code <= $to) {
                return self::DENIED;
            }
        }

        return self::OTHER;
    }

    /**
     * Whether this event means a person actually passed the reader. The only
     * events a worked day may be built from.
     */
    public static function isAccessGranted(int|string|null $code): bool
    {
        return self::classify($code) === self::GRANTED;
    }

    /**
     * The `event_code` a raw event is stored under. The BioStar code is kept
     * in the suffix so an operator looking at one row can still tell exactly
     * which of the 336 types it was, without the semantic meaning being lost.
     */
    public static function eventCodeFor(int|string|null $code): string
    {
        $classification = self::classify($code);

        return $classification === self::OTHER
            ? 'biostar:'.($code ?? 'unknown')
            : $classification;
    }
}
