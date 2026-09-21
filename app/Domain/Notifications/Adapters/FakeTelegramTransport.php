<?php

namespace App\Domain\Notifications\Adapters;

use App\Domain\Notifications\Contracts\TelegramTransportInterface;
use RuntimeException;

/**
 * NOTIFY-01: the only TelegramTransportInterface implementation this ticket
 * builds — a real, inspectable double (records every attempted send in
 * memory), never a silent no-op, so a test can assert exactly what would
 * have been sent to a real chat id. `$forcedFailureChatIds` lets a test
 * simulate a provider failure for one specific chat id without any global
 * flag that could leak between tests.
 */
class FakeTelegramTransport implements TelegramTransportInterface
{
    /**
     * @var list<array{chat_id: string, text: string}>
     */
    private array $sent = [];

    /**
     * @var list<string>
     */
    private array $forcedFailureChatIds = [];

    public function sendMessage(string $chatId, string $text): void
    {
        if (in_array($chatId, $this->forcedFailureChatIds, true)) {
            throw new RuntimeException('Simulated Telegram provider failure for chat '.$chatId);
        }

        $this->sent[] = ['chat_id' => $chatId, 'text' => $text];
    }

    public function forceFailureFor(string $chatId): void
    {
        $this->forcedFailureChatIds[] = $chatId;
    }

    /**
     * @return list<array{chat_id: string, text: string}>
     */
    public function sentMessages(): array
    {
        return $this->sent;
    }
}
