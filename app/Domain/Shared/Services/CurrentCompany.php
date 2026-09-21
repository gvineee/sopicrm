<?php

namespace App\Domain\Shared\Services;

use RuntimeException;

final class CurrentCompany
{
    private static ?string $id = null;

    public static function set(?string $companyId): void
    {
        self::$id = $companyId;
    }

    public static function id(): ?string
    {
        return self::$id;
    }

    public static function requireId(): string
    {
        return self::$id ?? throw new RuntimeException('No current company context has been established.');
    }

    public static function clear(): void
    {
        self::$id = null;
    }
}
