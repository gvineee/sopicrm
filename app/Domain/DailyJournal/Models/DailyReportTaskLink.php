<?php

namespace App\Domain\DailyJournal\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Tasks\Models\Task;
use Database\Factories\DailyReportTaskLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `daily_report_task_links` (database/migrations/2026_09_16_110000_create_daily_report_task_links_table.php)
 * — the reference link spec section 11 requires between a daily journal
 * entry and the task(s) it concerns. Deliberately carries no quantity/amount
 * of its own: the linked Task's own `accepted_quantity` is read live
 * wherever this link is displayed, never copied into a second figure here
 * (spec 11: "არა განმეორებითი ფინანსური დარიცხვით").
 */
class DailyReportTaskLink extends Model
{
    /** @use HasFactory<DailyReportTaskLinkFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'daily_report_id',
        'task_id',
        'note',
    ];

    /**
     * @return BelongsTo<DailyReport, $this>
     */
    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected static function newFactory(): DailyReportTaskLinkFactory
    {
        return DailyReportTaskLinkFactory::new();
    }
}
