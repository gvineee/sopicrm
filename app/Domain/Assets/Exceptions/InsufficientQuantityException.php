<?php

namespace App\Domain\Assets\Exceptions;

/**
 * spec 9.2: "საბოლოო გაცემა მხოლოდ ხელმისაწვდომი ნაშთით" — a quantity/
 * consumable line item requesting more than `quantity_on_hand`.
 */
class InsufficientQuantityException extends AssetDomainException
{
    public static function forAsset(string $assetId, float $requested, float $available): self
    {
        return new self("აქტივზე ({$assetId}) მოთხოვნილია {$requested}, ხელმისაწვდომია მხოლოდ {$available}.");
    }
}
