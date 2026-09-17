<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable replay-protection record for the internal device-connector API.
 * A connector retry must use a fresh nonce while retaining the same
 * Idempotency-Key; the latter replays the original application response.
 */
class DeviceConnectorNonce extends Model
{
    use BelongsToOrganization, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'personal_access_token_id',
        'nonce',
        'request_timestamp',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request_timestamp' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
