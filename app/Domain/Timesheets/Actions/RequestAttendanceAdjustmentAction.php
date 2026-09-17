<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\DataTransferObjects\AttendanceAdjustmentRequestData;
use App\Domain\Timesheets\Exceptions\InvalidAdjustmentInputException;
use App\Domain\Timesheets\Exceptions\NegativeDurationException;
use App\Domain\Timesheets\Exceptions\OverlappingAttendanceException;
use Illuminate\Support\Facades\DB;

/**
 * spec section 7: "ხელით შესწორების ფორმა: თანამშრომელი, თარიღი, ობიექტი,
 * სწორი IN/OUT ან საათები, მიზეზი, მტკიცებულება, ავტორი, დამმტკიცებელი.
 * ორიგინალი არ გადაიწეროს. გადაფარული სესიები და უარყოფითი ხანგრძლივობა
 * დაიბლოკოს."
 *
 * This Action only ever INSERTs a new `attendance_adjustments` row — it
 * never updates or deletes an `attendance_sessions` row (REQ-TSH-01's
 * "original session never overwritten").
 */
class RequestAttendanceAdjustmentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(AttendanceAdjustmentRequestData $data): AttendanceAdjustment
    {
        $this->assertValidInputShape($data);

        return DB::transaction(function () use ($data) {
            $this->assertNoOverlap($data);

            $adjustment = AttendanceAdjustment::create([
                'employee_id' => $data->employeeId,
                'work_date' => $data->workDate,
                'site_id' => $data->siteId,
                'corrected_clock_in_at' => $data->correctedClockInAt,
                'corrected_clock_out_at' => $data->correctedClockOutAt,
                'corrected_hours' => $data->correctedHours,
                'reason' => $data->reason,
                'evidence_attachment_id' => $data->evidenceAttachmentId,
                'requested_by_user_id' => $data->requestedByUserId,
                'status' => 'pending',
                'original_session_id' => $data->originalSessionId,
                'for_locked_period' => $data->forLockedPeriod,
            ]);

            $this->auditLogger->log(
                action: 'timesheets.adjustment.requested',
                target: $adjustment,
                after: $adjustment->getAttributes(),
                reason: $data->reason,
            );

            return $adjustment;
        });
    }

    private function assertValidInputShape(AttendanceAdjustmentRequestData $data): void
    {
        $hasTimes = $data->correctedClockInAt !== null && $data->correctedClockOutAt !== null;
        $hasOneTimeOnly = ($data->correctedClockInAt !== null) !== ($data->correctedClockOutAt !== null);
        $hasHours = $data->correctedHours !== null;

        if ($hasOneTimeOnly) {
            throw InvalidAdjustmentInputException::make(
                'Both corrected clock-in and clock-out must be given together.',
                ['corrected_clock_out_at' => ['შესწორებული შესვლისა და გასვლის დრო ერთად უნდა მიეთითოს.']],
            );
        }

        if ($data->flagOnly) {
            // The "late raw event after lock" flag-only path deliberately
            // has no proposed correction yet (spec: "ქმნის adjustment
            // request-ს" — a request for a human to review, not an
            // automatic fix) — see HandleLateArrivingEventAction.
            return;
        }

        if ($hasTimes === $hasHours) {
            throw InvalidAdjustmentInputException::make(
                'Exactly one of a corrected clock-in/out pair or corrected hours must be given.',
                ['corrected_hours' => ['მიუთითეთ ან შესწორებული შესვლა/გასვლა, ან საათები — ორივე ერთად ან არცერთი დაუშვებელია.']],
            );
        }

        if ($hasTimes && $data->correctedClockOutAt->lessThanOrEqualTo($data->correctedClockInAt)) {
            throw new NegativeDurationException;
        }

        if ($data->correctedHours !== null && (! is_numeric($data->correctedHours) || bccomp($data->correctedHours, '0', 2) <= 0)) {
            throw InvalidAdjustmentInputException::make(
                'Corrected hours must be a positive amount.',
                ['corrected_hours' => ['საათები დადებითი რიცხვი უნდა იყოს.']],
            );
        }
    }

    private function assertNoOverlap(AttendanceAdjustmentRequestData $data): void
    {
        if ($data->correctedClockInAt === null || $data->correctedClockOutAt === null) {
            // Hours-only or flag-only corrections have no explicit wall-clock
            // window to overlap-check against.
            return;
        }

        $overlapsSession = AttendanceSession::query()
            ->where('employee_id', $data->employeeId)
            ->where('status', '!=', 'superseded')
            ->when($data->originalSessionId, fn ($q) => $q->where('id', '!=', $data->originalSessionId))
            ->where('clock_in_at', '<', $data->correctedClockOutAt)
            ->where(function ($query) use ($data) {
                $query->whereNull('clock_out_at')
                    ->orWhere('clock_out_at', '>', $data->correctedClockInAt);
            })
            ->lockForUpdate()
            ->exists();

        if ($overlapsSession) {
            throw new OverlappingAttendanceException(
                $data->employeeId,
                "{$data->correctedClockInAt->toIso8601String()} .. {$data->correctedClockOutAt->toIso8601String()}",
            );
        }

        $overlapsAdjustment = AttendanceAdjustment::query()
            ->where('employee_id', $data->employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('corrected_clock_in_at')
            ->whereNotNull('corrected_clock_out_at')
            ->where('corrected_clock_in_at', '<', $data->correctedClockOutAt)
            ->where('corrected_clock_out_at', '>', $data->correctedClockInAt)
            ->lockForUpdate()
            ->exists();

        if ($overlapsAdjustment) {
            throw new OverlappingAttendanceException(
                $data->employeeId,
                "{$data->correctedClockInAt->toIso8601String()} .. {$data->correctedClockOutAt->toIso8601String()}",
            );
        }
    }
}
