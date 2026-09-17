<?php

namespace App\Domain\DailyJournal\Actions;

use App\Domain\DailyJournal\Exceptions\InvalidDailyReportStateException;
use App\Domain\DailyJournal\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\DailyJournal\Exceptions\StaleDailyReportVersionException;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The manager-acceptance half of spec section 11's workflow. Self-approval
 * is denied by default per spec section 3 ("საკუთარი ტაბელის ან საკუთარი
 * ფინანსური მოთხოვნის საბოლოო დამტკიცება ნაგულისხმევად აკრძალულია") — the
 * one documented exception is the owner role acting under a SEPARATE
 * `dailyjournal.reports.accept-own` permission, and even then the resulting
 * Approval/AuditEvent is flagged high-risk so it surfaces in the owner's own
 * audit trail rather than passing silently (spec: "მაღალი რისკის ქმედებები
 * აუდიტში").
 */
class AcceptDailyReportAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(DailyReport $report, int $targetVersion, User $actor, ?string $notes = null): DailyReport
    {
        if ($report->status !== 'submitted') {
            throw new InvalidDailyReportStateException('accept', $report->status);
        }

        if ($report->version !== $targetVersion) {
            throw new StaleDailyReportVersionException($report->version, $targetVersion);
        }

        $isSelfApproval = $report->submitted_by_user_id === $actor->id;
        $ownerSelfApprovalException = $isSelfApproval
            && $actor->hasRole('owner')
            && $actor->can('dailyjournal.reports.accept-own');

        if ($isSelfApproval && ! $ownerSelfApprovalException) {
            throw new SelfApprovalNotAllowedException;
        }

        return DB::transaction(function () use ($report, $actor, $notes, $ownerSelfApprovalException) {
            $before = $report->getAttributes();

            $report->fill([
                'status' => 'accepted',
                'accepted_at' => now(),
                'accepted_by_user_id' => $actor->id,
            ])->save();

            Approval::create([
                'approvable_type' => $report->getMorphClass(),
                'approvable_id' => $report->id,
                'target_version' => $before['version'],
                'approver_user_id' => $actor->id,
                'decision' => 'accepted',
                'reason' => $notes,
                'decided_at' => now(),
            ]);

            $this->auditLogger->log(
                action: 'daily_journal.report.accepted',
                target: $report,
                before: $before,
                after: $report->getAttributes(),
                reason: $notes,
                actor: $actor,
            );

            if ($ownerSelfApprovalException) {
                $this->auditLogger->log(
                    action: 'daily_journal.report.self_accepted_by_owner_exception',
                    target: $report,
                    reason: $notes ?? 'owner self-approval exception (spec section 3)',
                    actor: $actor,
                );
            }

            return $report;
        });
    }
}
