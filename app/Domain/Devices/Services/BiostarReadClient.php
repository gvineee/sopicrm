<?php

namespace App\Domain\Devices\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A read-only client for the BioStar 2 local API.
 *
 * Read-only is the point, not an accident of what happens to be implemented:
 * this phase has BioStar owning devices, cards and access, and the CRM only
 * copying what it sees. There is no method here that opens a door, issues or
 * revokes a card, creates a BioStar user or changes device configuration — so
 * no caller can reach for one by mistake. The write path, when it is
 * pilot-confirmed against real hardware, lives in the device-connector behind
 * `biostar_write_dispatch_enabled`.
 *
 * It exists as one class because the login handshake, the TLS decision and the
 * shape of a card lookup were being restated in each command that needed them,
 * and a second copy of "how do we trust this certificate" is exactly the copy
 * that gets it wrong.
 */
class BiostarReadClient
{
    private ?PendingRequest $authed = null;

    public function __construct(private readonly CardIdentifierNormalizer $normalizer) {}

    public function baseUrl(): string
    {
        return rtrim((string) config('devices.biostar.base_url', ''), '/');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== ''
            && (string) config('devices.biostar.username', '') !== ''
            && (string) config('devices.biostar.password', '') !== '';
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = [], ?string $key = null): array
    {
        $response = $this->session()->get($this->baseUrl().$path, $query);

        return $this->arrayFrom($response->json($key));
    }

    /**
     * Only ever used for endpoints whose POST is a SEARCH — BioStar models its
     * event query that way. Nothing here changes state upstream.
     *
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     */
    public function search(string $path, array $body, ?string $key = null): array
    {
        $response = $this->session()->post($this->baseUrl().$path, $body);

        return $this->arrayFrom($response->json($key));
    }

    /**
     * The card each BioStar person carries, already converted to the hex the
     * CRM's ingest reads.
     *
     * Events name the PERSON but never the card, while the CRM matches a swipe
     * to an employee through the card — so without this every imported event
     * would arrive with no credential, no employee and no attendance. The list
     * endpoint reports only a `card_count`, so the card itself has to come
     * from each holder's own record.
     *
     * @return array<string, array{card_type: string, card_hex: string, card_decimal: string}>
     */
    public function cardsByUserId(): array
    {
        $cards = [];

        foreach ($this->get('/api/users', ['limit' => 1000], 'UserCollection.rows') as $row) {
            if (! is_array($row) || (int) ($row['card_count'] ?? 0) < 1) {
                continue;
            }

            $userId = (string) ($row['user_id'] ?? '');
            $card = $this->preferredCard($userId);

            if ($userId === '' || $card === null) {
                continue;
            }

            $decimal = (string) $card['card_id'];

            if (! ctype_digit($decimal)) {
                continue;
            }

            $cardType = is_array($card['card_type'] ?? null) ? (string) ($card['card_type']['name'] ?? 'CSN') : 'CSN';

            $cards[$userId] = [
                'card_type' => $cardType,
                // BioStar reports the number in DECIMAL while the ingest path
                // reads hex and converts back. Passing the decimal through
                // unchanged would be read as hex and land on a different card
                // entirely — 69410222 would arrive as 1765868066.
                'card_hex' => $this->normalizer->fromDecimal($decimal, $cardType, 32)->rawBytesHex,
                'card_decimal' => $decimal,
            ];
        }

        return $cards;
    }

    /**
     * The blocked card is skipped where an unblocked one exists: a person whose
     * card was replaced should be matched by the card they actually carry.
     *
     * @return array<string, mixed>|null
     */
    private function preferredCard(string $userId): ?array
    {
        $cards = $this->get("/api/users/{$userId}", [], 'User.cards');
        $fallback = null;

        foreach ($cards as $card) {
            if (! is_array($card) || ! isset($card['card_id'])) {
                continue;
            }

            $fallback ??= $card;

            if (($card['is_blocked'] ?? 'false') !== 'true') {
                return $card;
            }
        }

        return $fallback;
    }

    private function session(): PendingRequest
    {
        if ($this->authed !== null) {
            return $this->authed;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('BIOSTAR_BASE_URL / BIOSTAR_USERNAME / BIOSTAR_PASSWORD არ არის კონფიგურირებული.');
        }

        $caPath = config('devices.biostar.ca_cert_path');

        $client = Http::asJson()
            ->timeout((int) config('devices.biostar.request_timeout_ms', 15000) / 1000)
            ->withOptions([
                'verify' => is_string($caPath) && $caPath !== ''
                    ? $caPath
                    : (bool) config('devices.biostar.verify_tls', true),
            ]);

        $login = $client->post($this->baseUrl().'/api/login', [
            'User' => [
                'login_id' => (string) config('devices.biostar.username'),
                'password' => (string) config('devices.biostar.password'),
            ],
        ]);

        $session = $login->header('bs-session-id');

        if ($session === '') {
            throw new RuntimeException('BioStar-ში ავტორიზაცია ვერ მოხერხდა (სესიის დასტური არ დაბრუნდა).');
        }

        return $this->authed = $client->withHeaders(['bs-session-id' => $session]);
    }

    /**
     * @return array<mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
