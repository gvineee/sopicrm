<?php

namespace App\Http\Requests\Concerns;

/**
 * 03-Construction-Task-Manager-Spec-KA.md §13.2: "ყოველი ჩამწერი ბრძანება
 * ატარებს expected version-ს და idempotency key-ს". Both are optional on the
 * wire — a desktop form that omits them still works — but when a client does
 * send them the server honours them: a stale version loses, and a replayed
 * key resolves to the decision it already made rather than making a second
 * one.
 *
 * Neither value is ever trusted for anything else. The actor comes from the
 * authenticated session and the time from the server, so a client cannot use
 * this envelope to claim who decided or when.
 */
trait CarriesWriteCommandEnvelope
{
    /**
     * @return array<string, mixed>
     */
    public function envelopeRules(): array
    {
        return [
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function expectedVersion(): ?int
    {
        $value = $this->input('expected_version');

        return is_numeric($value) ? (int) $value : null;
    }

    public function idempotencyKey(): ?string
    {
        $value = $this->input('idempotency_key') ?? $this->header('Idempotency-Key');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
