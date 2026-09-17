<?php

namespace App\Domain\Devices\DataTransferObjects;

/**
 * Result of a DeviceAdapterInterface::applyCommand() call — deliberately
 * separate from the `DeviceSyncCommand` Eloquent model so an adapter never
 * has to know about (or mutate) persistence directly; the calling Action
 * (App\Domain\Devices\Actions\ProcessDeviceSyncCommandAction) is the only
 * place that writes the outcome back to the row.
 */
final class DeviceCommandResult
{
    public function __construct(
        public readonly bool $applied,
        /**
         * Never treat this as "the device is reachable" on its own — a
         * device correctly reports itself unreachable/offline without that
         * being an adapter-level error (spec section 6: an offline device's
         * pending revocation must never render as completed). `applied =
         * false` with `retryable = true` and no exception is the expected,
         * normal shape for "device offline right now."
         */
        public readonly bool $retryable = true,
        public readonly ?string $error = null,
        /**
         * Opaque adapter-specific echo of what the device actually
         * acknowledged (simulator: the state it recorded; real adapter:
         * whatever the G-SDK call returned) — stored on the command row for
         * audit/debugging, never parsed by generic Domain code.
         *
         * @var array<string, mixed>
         */
        public readonly array $deviceEcho = [],
    ) {}

    /**
     * @param  array<string, mixed>  $deviceEcho
     */
    public static function success(array $deviceEcho = []): self
    {
        return new self(applied: true, retryable: false, deviceEcho: $deviceEcho);
    }

    public static function deviceUnreachable(string $reason): self
    {
        return new self(applied: false, retryable: true, error: $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self(applied: false, retryable: false, error: $reason);
    }
}
