<?php

namespace App\Policies;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Models\User;

/**
 * spec section 3 explicit carve-out: "პროექტის მენეჯერი ... თანამშრომელთა
 * პირადი ტარიფები დახურულია" (a project manager's own project/tasks/
 * resources access does NOT extend to employees' personal rates) and the
 * mandatory test row "PM ითხოვს ხელფასის API/export-ს უფლების გარეშე →
 * ფინანსური მონაცემი არ გაიცემა." Rate MANAGEMENT (spec: "ცვლილების
 * მიზეზი და დამმტკიცებელი") sits with whoever actually owns pay decisions —
 * `finance`/`owner`, per the role table's "ფინანსისტი: ტარიფები." HR can
 * VIEW (needed for onboarding/roster context) but not approve a rate
 * change. An employee may view their own current/historical rate for
 * transparency, but never anyone else's.
 */
class RateHistoryPolicy
{
    public function viewAny(User $user, string $employeeId): bool
    {
        return $this->canView($user, $employeeId);
    }

    public function view(User $user, RateHistory $rateHistory): bool
    {
        if ($rateHistory->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->canView($user, $rateHistory->employee_id);
    }

    public function create(User $user): bool
    {
        return $user->can('employees.rates.manage');
    }

    private function canView(User $user, string $employeeId): bool
    {
        if ($user->can('employees.rates.view') || $user->can('employees.rates.manage')) {
            return true;
        }

        return Employee::query()
            ->where('id', $employeeId)
            ->where('user_id', $user->id)
            ->exists();
    }
}
