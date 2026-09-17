<?php

namespace App\Domain\Assets\Exceptions;

/**
 * Thrown when an issue/transfer is attempted on an asset whose condition
 * makes it ineligible (under_repair — spec 9.3's quarantine/repair rule — or
 * written_off — spec 9.5).
 */
class AssetNotAvailableException extends AssetDomainException
{
    public static function forCondition(string $assetId, string $condition): self
    {
        return new self("აქტივი ({$assetId}) არ არის ხელმისაწვდომი გასაცემად, მდგომარეობა: {$condition}.");
    }
}
