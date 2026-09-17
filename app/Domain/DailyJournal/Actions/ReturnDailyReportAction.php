<?php

namespace App\Domain\DailyJournal\Actions;

use App\Domain\DailyJournal\Exceptions\InvalidDailyReportStateException;
use App\Domain\DailyJournal\Exceptions\StaleDailyReportVersionException;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The manager sends a `submitted` report back to `draft` for correction —
 * the counterpart to AcceptDailyReportAction. Not explicitly named in spec
 * section 11's own text (which only says "შევსება → წარდგენა → მენეჯერის
 * მიღება"), but is the same "reviewer can return with a reason" pattern
 * spec section 10 uses for task submissions, and is necessary for the
 * acceptance step to mean anything (otherwise a manager's only options for
 * an incomplete report are "accept it anyway" or "silently edit it
 * themselves"). Recorded as its own routine decision in docs/decisions.md.
 */
class ReturnDailyReportAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(DailyReport $report, int $targetVersion, User $actor, string $reason): DailyReport
    {
        if ($report->status !== 'submitted') {
            throw new InvalidDailyReportStateException('return', $report->status);
        }

        if ($report->version !== $targetVersion) {
            throw new StaleDailyReportVersionException($report->version, $targetVersion);
        }

        return DB::transaction(function () use ($report, $actor, $reason) {
            $before = $report->getAttributes();

            $report->fill(['status' => 'draft'])->save();

            Approval::create([
                'approvable_type' => $report->getMorphClass(),
                'approvable_id' => $report->id,
                'target_version' => $before['version'],
                'approver_user_id' => $actor->id,
                'decision' => 'returned',
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            $this->auditLogger->log(
                action: 'daily_journal.report.returned',
                target: $report,
                before: $before,
                after: $report->getAttributes(),
                reason: $reason,
                actor: $actor,
            );

            return $report;
        });
    }
}
