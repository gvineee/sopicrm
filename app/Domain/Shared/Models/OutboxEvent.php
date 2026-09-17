<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * docs/data-model.md "outbox_events" — see
 * App\Domain\Shared\Services\OutboxDispatcher::record() (the only
 * supported way to create a row: always inside the caller's own DB
 * transaction) and App\Console\Commands\RelayOutboxEvents (the relay that
 * turns unprocessed rows into queued jobs).
 *
 * `HasFactory`/`OutboxEventFactory` added by the P0+P1 schema pass for test
 * convenience only.
 *
 * @property array<string, mixed> $payload
 */
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'event_type',
        'subject_type',
        'subject_id',
        'payload',
        'available_at',
        'processed_at',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<OutboxEvent>  $query
     * @return Builder<OutboxEvent>
     */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at')
            ->where('available_at', '<=', now())
            ->orderBy('available_at');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return OutboxEventFactory
     */
    protected static function newFactory()
    {
        return OutboxEventFactory::new();
    }
}
