<?php

namespace App\Domain\Devices\DataTransferObjects;

/**
 * Result of App\Domain\Devices\Services\CardIdentifierNormalizer — spec
 * section 6: "ნომრის bytes/სიგრძე/leading zeros შეინარჩუნე; decimal/hex და
 * byte-order გარდაქმნა იყოს ადაპტერის დოკუმენტირებული ნაწილი." The decimal
 * `canonicalIdentifier` is what `credentials.canonical_identifier` stores
 * and what uniquely identifies the physical card within an organization;
 * `rawBytesHex`/`bitLength` are what let the original leading zeros be
 * reconstructed for display or re-transmission to a device, since a bare
 * decimal integer cannot represent them.
 */
final class NormalizedCardNumber
{
    public function __construct(
        public readonly string $cardType,
        public readonly string $canonicalIdentifier,
        public readonly ?string $rawBytesHex,
        public readonly int $bitLength,
        public readonly bool $leadingZerosPreserved = true,
    ) {}
}
