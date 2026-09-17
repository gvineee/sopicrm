<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use Database\Factories\IdempotencyRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/data-model.md "idempotency_records" — written/read exclusively by
 * App\Http\Middleware\EnsureIdempotencyKey. Never constructed directly by
 * controllers. `HasFactory`/`IdempotencyRecordFactory` added by the P0+P1
 * schema pass for test convenience only.
 */
class IdempotencyRecord extends Model
{
    /** @use HasFactory<IdempotencyRecordFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'idempotency_key',
        'endpoint_signature',
        'request_hash',
        'response_status',
        'response_body',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return IdempotencyRecordFactory
     */
    protected static function newFactory()
    {
        return IdempotencyRecordFactory::new();
    }
}
