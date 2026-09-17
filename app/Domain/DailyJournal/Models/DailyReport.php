<?php

namespace App\Domain\DailyJournal\Models;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Approval;
use App\Models\User;
use Database\Factories\DailyReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "daily_reports" (spec section 11, P1 baseline). Editing
 * an already-accepted/closed day creates a `DailyReportRevision` (append,
 * never overwrite). Work-quantity figures link to `tasks.accepted_quantity`
 * by reference (see `taskLinks()`/`daily_report_task_links`) rather than
 * storing a second independent financial figure (spec: "არა განმეორებითი
 * ფინანსური დარიცხვით").
 *
 * `status`: draft -> submitted -> accepted (spec: "შევსება → წარდგენა →
 * მენეჯერის მიღება"). A manager can also return a `submitted` report back to
 * `draft` with a reason (recorded as an `Approval` row with
 * decision='returned' — see App\Domain\DailyJournal\Actions\ReturnDailyReportAction).
 * `submitted_at/by`/`accepted_at/by` (2026_09_17_100000_add_workflow_columns_to_daily_reports_table.php)
 * are a denormalized "current state" convenience; the actual approval
 * decision history lives in the polymorphic `approvals` table via
 * `approvals()`.
 */
/**
 * @property Carbon $report_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $accepted_at
 */
class DailyReport extends Model
{
    /** @use HasFactory<DailyReportFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'project_id',
        'report_date',
        'responsible_user_id',
        'teams_present',
        'headcount_from_attendance',
        'headcount_manual_override',
        'headcount_variance_note',
        'work_performed_note',
        'equipment_used',
        'materials_received_note',
        'delays_note',
        'quality_safety_note',
        'photo_attachment_ids',
        'next_day_plan',
        'weather_manual',
        'status',
        'submitted_at',
        'submitted_by_user_id',
        'accepted_at',
        'accepted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'teams_present' => 'array',
            'equipment_used' => 'array',
            'photo_attachment_ids' => 'array',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'headcount_from_attendance' => 'integer',
            'headcount_manual_override' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * @return HasMany<DailyReportRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(DailyReportRevision::class);
    }

    /**
     * The section-11 reference link to task accepted quantity — deliberately
     * carries no amount of its own, see DailyReportTaskLink's own docblock.
     *
     * @return HasMany<DailyReportTaskLink, $this>
     */
    public function taskLinks(): HasMany
    {
        return $this->hasMany(DailyReportTaskLink::class);
    }

    /**
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    protected static function newFactory(): DailyReportFactory
    {
        return DailyReportFactory::new();
    }
}
