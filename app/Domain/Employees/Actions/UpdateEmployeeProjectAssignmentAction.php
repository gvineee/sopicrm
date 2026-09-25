<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\EmployeeProjectAssignment;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Audit A10: „ჩანს პროექტი და პერიოდი, მაგრამ არ ჩანს ამ მინიჭების
 * რედაქტირება/დასრულება/გადაყვანა."
 *
 * Only creation existed. An assignment entered with the wrong date, or one
 * that simply ended, could never be corrected or closed by any route — the
 * only way to stop it was to leave a wrong record standing.
 *
 * Ending an assignment and correcting one are the same write: both set the
 * period, and both are audited with before/after, because an assignment
 * period is what the attendance and payroll modules read to decide which
 * project a worked day belongs to. Changing it silently would move money.
 */
class UpdateEmployeeProjectAssignmentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{starts_on?: string, ends_on?: string|null, assignment_type?: string|null}  $data
     */
    public function execute(EmployeeProjectAssignment $assignment, array $data, User $actor): EmployeeProjectAssignment
    {
        $before = $assignment->only(['project_id', 'starts_on', 'ends_on', 'assignment_type']);

        $startsOn = $data['starts_on'] ?? $assignment->starts_on->toDateString();
        $endsOn = array_key_exists('ends_on', $data) ? $data['ends_on'] : $assignment->ends_on?->toDateString();

        if ($endsOn !== null && $endsOn < $startsOn) {
            throw ValidationException::withMessages([
                'ends_on' => 'დასრულების თარიღი დაწყებაზე ადრე ვერ იქნება.',
            ]);
        }

        // The model casts both columns to a date, so they are assigned as
        // dates rather than as the strings the request carried.
        $assignment->starts_on = Carbon::parse($startsOn);
        $assignment->ends_on = $endsOn === null ? null : Carbon::parse($endsOn);

        if (array_key_exists('assignment_type', $data)) {
            $assignment->assignment_type = $data['assignment_type'];
        }

        $assignment->save();

        $this->auditLogger->log(
            action: 'employees.project_assignment.updated',
            target: $assignment,
            before: $before,
            after: $assignment->only(['project_id', 'starts_on', 'ends_on', 'assignment_type']),
            actor: $actor,
        );

        return $assignment;
    }
}
