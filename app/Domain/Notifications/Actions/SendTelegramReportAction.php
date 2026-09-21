<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Devices\Models\Device;
use App\Domain\Notifications\Contracts\TelegramTransportInterface;
use App\Domain\Notifications\Exceptions\TelegramReportNotAuthorizedException;
use App\Domain\Notifications\Models\TelegramLink;
use App\Domain\Notifications\Models\TelegramReportDelivery;
use App\Domain\Notifications\Support\TelegramReportType;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * NOTIFY-01: sends exactly one read-only report to the user's linked
 * Telegram chat. Re-checks the recipient's CURRENT permission (or, for the
 * financial summary, the same 2FA-aware `access-financial-data` Gate used
 * everywhere else in this codebase) immediately before formatting the
 * report body — never at link time — so a user demoted or deactivated
 * after linking stops receiving privileged summaries on their very next
 * request, per this ticket's own acceptance line ("გაუქმებული წევრობა
 * წყვეტს გაგზავნას"). A denied check never even creates a `queued` delivery
 * row for the requested content — it throws before any send is attempted.
 */
class SendTelegramReportAction
{
    public function __construct(private readonly TelegramTransportInterface $transport) {}

    public function execute(TelegramLink $link, string $reportType, User $requestedBy): TelegramReportDelivery
    {
        if (! $link->isLinked()) {
            throw new TelegramReportNotAuthorizedException('This account is not linked to Telegram.');
        }

        $recipient = $link->user;

        $this->assertCurrentlyAuthorized($recipient, $reportType);

        $delivery = TelegramReportDelivery::query()->create([
            'telegram_link_id' => $link->id,
            'requested_by_user_id' => $requestedBy->id,
            'report_type' => $reportType,
            'status' => 'queued',
            'created_at' => now(),
        ]);

        try {
            $text = $this->buildReportText($recipient, $reportType);
            $this->transport->sendMessage($link->telegram_chat_id, $text);
            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'failed_reason' => mb_substr($exception->getMessage(), 0, 500),
            ]);
        }

        return $delivery->fresh();
    }

    /**
     * Re-dispatches the SAME delivery row's report type against the
     * recipient's live permissions — never resends an already-`sent`
     * delivery (matches TIMESHEET-EMAIL-01's identical "retry only touches
     * a failed row" rule).
     */
    public function retry(TelegramReportDelivery $delivery): TelegramReportDelivery
    {
        if ($delivery->status !== 'failed') {
            throw new TelegramReportNotAuthorizedException('Only a failed delivery can be retried.');
        }

        $link = $delivery->telegramLink;
        $recipient = $link->user;

        $this->assertCurrentlyAuthorized($recipient, $delivery->report_type);

        try {
            $text = $this->buildReportText($recipient, $delivery->report_type);
            $this->transport->sendMessage($link->telegram_chat_id, $text);
            $delivery->update(['status' => 'sent', 'sent_at' => now(), 'failed_reason' => null]);
        } catch (Throwable $exception) {
            $delivery->update(['failed_reason' => mb_substr($exception->getMessage(), 0, 500)]);
        }

        return $delivery->fresh();
    }

    private function assertCurrentlyAuthorized(User $recipient, string $reportType): void
    {
        if ($reportType === TelegramReportType::FINANCIAL_SUMMARY) {
            if (! Gate::forUser($recipient)->allows('access-financial-data')) {
                throw new TelegramReportNotAuthorizedException(
                    'The linked user no longer has authorized financial access.'
                );
            }

            return;
        }

        $permission = TelegramReportType::requiredPermissions()[$reportType] ?? null;

        if ($permission === null || ! $recipient->can($permission)) {
            throw new TelegramReportNotAuthorizedException(
                'The linked user no longer has permission for this report.'
            );
        }
    }

    /**
     * Every figure here is a real, current count scoped to the recipient's
     * own organization — never a fabricated example. No full salary amount
     * or card number appears even in the financial summary; it reports
     * counts/aggregates only, matching the same "safe preview" posture
     * App\Domain\Notifications\Support\SensitivePreviewGuard enforces for
     * in-app notifications.
     */
    private function buildReportText(User $recipient, string $reportType): string
    {
        return match ($reportType) {
            TelegramReportType::ATTENDANCE_SUMMARY => $this->attendanceSummary($recipient),
            TelegramReportType::EXCEPTIONS => $this->exceptionsSummary($recipient),
            TelegramReportType::OVERDUE_WORK => $this->overdueWorkSummary($recipient),
            TelegramReportType::PENDING_ACCEPTANCE => $this->pendingAcceptanceSummary($recipient),
            TelegramReportType::BIOSTAR_IMPORT_LAG => $this->biostarImportLagSummary($recipient),
            TelegramReportType::FINANCIAL_SUMMARY => $this->financialSummary($recipient),
            default => throw new TelegramReportNotAuthorizedException("Unknown report type: {$reportType}"),
        };
    }

    private function attendanceSummary(User $recipient): string
    {
        $openToday = AttendanceSession::query()
            ->where('organization_id', $recipient->organization_id)
            ->whereDate('work_date', now()->toDateString())
            ->count();

        return "დასწრების მოკლე ანგარიში\nდღეს დაფიქსირებული სესია: {$openToday}";
    }

    private function exceptionsSummary(User $recipient): string
    {
        $unresolved = AttendanceAnomaly::query()
            ->where('organization_id', $recipient->organization_id)
            ->whereNull('resolved_at')
            ->count();

        return "გამონაკლისები\nგადაუწყვეტელი ანომალია: {$unresolved}";
    }

    private function overdueWorkSummary(User $recipient): string
    {
        $overdue = Task::query()
            ->where('organization_id', $recipient->organization_id)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        return "ვადაგადაცილებული სამუშაო\nვადაგადაცილებული დავალება: {$overdue}";
    }

    private function pendingAcceptanceSummary(User $recipient): string
    {
        $pending = Task::query()
            ->where('organization_id', $recipient->organization_id)
            ->where('status', 'submitted')
            ->count();

        return "მისაღები დავალებები\nმენეჯერის მიღებას ელოდება: {$pending}";
    }

    private function biostarImportLagSummary(User $recipient): string
    {
        $unhealthy = Device::query()
            ->where('organization_id', $recipient->organization_id)
            ->whereIn('status', ['offline', 'degraded'])
            ->count();

        $openDataGaps = AttendanceAnomaly::query()
            ->where('organization_id', $recipient->organization_id)
            ->whereNull('resolved_at')
            ->whereIn('anomaly_type', ['data_gap', 'out_of_order_events', 'clock_drift'])
            ->count();

        return "BioStar იმპორტის მდგომარეობა\nარა-online მოწყობილობა: {$unhealthy}\nღია მონაცემთა ხარვეზი: {$openDataGaps}";
    }

    private function financialSummary(User $recipient): string
    {
        $openPayRuns = PayRun::query()
            ->where('organization_id', $recipient->organization_id)
            ->whereNotIn('status', ['approved', 'locked'])
            ->count();

        return "ფინანსური შეჯამება (უფლებამოსილი)\nმიმდინარე გადახდის რანი: {$openPayRuns}";
    }
}
