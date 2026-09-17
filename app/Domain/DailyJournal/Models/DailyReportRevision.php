<?php

namespace App\Domain\DailyJournal\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Database\Factories\DailyReportRevisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "daily_report_revisions" (spec section 11: "დახურული
 * დღის რედაქტირება ქმნის revision-ს").
 */
/** @property Carbon|null $revised_at */
class DailyReportRevision extends Model
{
    /** @use HasFactory<DailyReportRevisionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'daily_report_id',
        'snapshot',
        'revised_by_user_id',
        'revised_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'revised_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DailyReport, $this>
     */
    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by_user_id');
    }

    protected static function newFactory(): DailyReportRevisionFactory
    {
        return DailyReportRevisionFactory::new();
    }
}
