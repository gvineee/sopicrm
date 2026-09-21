<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class UpdateShiftTemplateAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{site_id?: string|null, name?: string, starts_at_local?: string, ends_at_local?: string, crosses_midnight?: bool, scheduled_days?: array<int, string>, break_policy?: array<string, mixed>, allowed_late_minutes?: int, rounding_policy?: array<string, mixed>, requires_approval_by_role?: string|null}  $data
     */
    public function execute(ShiftTemplate $template, array $data, User $actor): ShiftTemplate
    {
        $before = $template->only(['name', 'starts_at_local', 'ends_at_local', 'break_policy', 'rounding_policy']);

        $template->update(array_intersect_key($data, array_flip([
            'site_id', 'name', 'starts_at_local', 'ends_at_local', 'crosses_midnight',
            'scheduled_days', 'break_policy', 'allowed_late_minutes', 'rounding_policy',
            'requires_approval_by_role',
        ])));

        $this->auditLogger->log(
            action: 'attendance.shift_template.updated',
            target: $template,
            before: $before,
            after: $template->only(['name', 'starts_at_local', 'ends_at_local', 'break_policy', 'rounding_policy']),
            actor: $actor,
        );

        return $template->fresh();
    }
}
