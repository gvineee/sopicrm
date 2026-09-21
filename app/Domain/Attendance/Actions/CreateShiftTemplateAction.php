<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * REQ-ATT-01: all thresholds (break policy, rounding policy, allowed
 * lateness) are configurable JSON on the row — never hardcoded here.
 */
class CreateShiftTemplateAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{site_id: string|null, name: string, starts_at_local: string, ends_at_local: string, crosses_midnight: bool, scheduled_days: array<int, string>, break_policy: array<string, mixed>, allowed_late_minutes: int, rounding_policy: array<string, mixed>, requires_approval_by_role: string|null}  $data
     */
    public function execute(array $data, User $actor): ShiftTemplate
    {
        $template = ShiftTemplate::query()->create([
            'site_id' => $data['site_id'],
            'name' => $data['name'],
            'starts_at_local' => $data['starts_at_local'],
            'ends_at_local' => $data['ends_at_local'],
            'crosses_midnight' => $data['crosses_midnight'],
            'scheduled_days' => $data['scheduled_days'],
            'break_policy' => $data['break_policy'],
            'allowed_late_minutes' => $data['allowed_late_minutes'],
            'rounding_policy' => $data['rounding_policy'],
            'requires_approval_by_role' => $data['requires_approval_by_role'],
        ]);

        $this->auditLogger->log(
            action: 'attendance.shift_template.created',
            target: $template,
            after: $template->only(['name', 'site_id', 'starts_at_local', 'ends_at_local']),
            actor: $actor,
        );

        return $template;
    }
}
