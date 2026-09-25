/**
 * BioStar 2's numeric event codes, translated into the semantic taxonomy the
 * CRM's attendance side reads. Mirrors
 * app/Domain/Devices/Support/BiostarEventTaxonomy.php — the two must agree,
 * and the PHP copy has the full reasoning.
 *
 * The short version of why this exists: the connector used to forward
 * `biostar:4102` unmapped, and the attendance rebuild excludes exactly one
 * code, `access_denied`. A `6401 ACCESS_DENIED_ACCESS_GROUP` is a badge the
 * door REFUSED, and unmapped it did not match that exclusion — so somebody
 * turned away at the gate could be counted as having arrived.
 *
 * The families were read from a live server's own /api/event_types (336 types
 * on that install), not guessed from documentation.
 */

const GRANTED_RANGES = [
    [4096, 4143], // VERIFY_SUCCESS_*
    [4864, 4879], // IDENTIFY_SUCCESS_*
    [5632, 5647], // DUAL_AUTH_SUCCESS_*
];

const DENIED_RANGES = [
    [4352, 4367], // VERIFY_FAIL_*
    [5120, 5135], // IDENTIFY_FAIL_*
    [5888, 5903], // DUAL_AUTH_FAIL_*
    [6144, 6159], // AUTH_FAILED_*, ACCESS_DENIED_LOCKED
    [6400, 6431], // ACCESS_DENIED_*
];

const inAnyRange = (code, ranges) => ranges.some(([from, to]) => code >= from && code <= to);

export const ACCESS_GRANTED = 'access_granted';
export const ACCESS_DENIED = 'access_denied';
export const OTHER = 'other';

export function classifyBiostarEvent(code) {
    const numeric = Number(code);

    if (!Number.isFinite(numeric)) return OTHER;
    if (inAnyRange(numeric, GRANTED_RANGES)) return ACCESS_GRANTED;
    if (inAnyRange(numeric, DENIED_RANGES)) return ACCESS_DENIED;

    return OTHER;
}

/**
 * The `event_code` the raw event is stored under. Codes outside the
 * access-control families keep their BioStar number, so an operator reading
 * one row can still tell exactly which of the 336 types it was.
 */
export function biostarEventCode(code) {
    const classification = classifyBiostarEvent(code);

    return classification === OTHER ? `biostar:${code ?? 'unknown'}` : classification;
}
