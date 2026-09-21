<?php

namespace App\Domain\Notifications\Contracts;

/**
 * NOTIFY-01: the boundary to the real Telegram Bot API. This ticket binds
 * ONLY App\Domain\Notifications\Adapters\FakeTelegramTransport — no real
 * HTTP call to Telegram exists anywhere in this codebase yet, mirroring the
 * FakeAccessControlProvider/SimulatorDeviceAdapter precedent for an
 * external-service boundary with no live credentials available this
 * session. A real implementation (e.g. via Telegram's Bot API sendMessage
 * endpoint) is future work once a real bot token exists.
 */
interface TelegramTransportInterface
{
    /**
     * @throws \RuntimeException on any transport-level failure — the caller
     *                           (SendTelegramReportAction) is responsible
     *                           for turning that into a failed delivery
     *                           row, never for retrying internally here.
     */
    public function sendMessage(string $chatId, string $text): void;
}
