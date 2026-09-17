<?php

namespace App\Domain\Assets\Exceptions;

/**
 * Spec section 9.6 hard rule: "ერთდროულად ორი გაცემიდან მხოლოდ ერთმა უნდა
 * შეძლოს იგივე აქტივის დაჯავშნა/გაცემა" — thrown when a
 * FinalizeIssueAction/TransferCustodyAction attempt loses the race for an
 * asset another transaction already holds a lock on (already
 * issued/awaiting_receipt/in_transit).
 */
class AssetAlreadyIssuedException extends AssetDomainException
{
    public static function forAsset(string $assetId): self
    {
        return new self("აქტივი ({$assetId}) უკვე გაცემულია ან გაცემის პროცესშია — ორმა ერთდროულმა მცდელობამ ვერ შეძლო წარმატება ერთდროულად.");
    }
}
