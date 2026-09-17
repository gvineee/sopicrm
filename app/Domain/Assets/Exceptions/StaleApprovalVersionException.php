<?php

namespace App\Domain\Assets\Exceptions;

/**
 * spec section 19 optimistic-concurrency rule, applied to write-off approval:
 * the AssetIncident changed since the approver loaded it (target_version
 * mismatch) — reject as stale (409) rather than approving a decision the
 * approver never actually saw.
 */
class StaleApprovalVersionException extends AssetDomainException
{
    public static function make(int $expected, int $actual): self
    {
        return new self("ჩანაწერი შეიცვალა დამტკიცების დაწყების შემდეგ (მოსალოდნელი ვერსია {$expected}, ფაქტობრივი {$actual}). გთხოვთ განაახლოთ გვერდი.");
    }
}
