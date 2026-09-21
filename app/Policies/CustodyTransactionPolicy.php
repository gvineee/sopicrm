<?php

namespace App\Policies;

use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Employees\Models\Employee;
use App\Models\User;

/**
 * `assets.custody.manage` covers issuing/finalizing/transferring/returning
 * on someone else's behalf (warehouse_keeper/owner/project_manager).
 * Confirming your own receipt and requesting your own return need no
 * permission at all — only that the acting user's own Employee record IS
 * the transaction's `receiving_employee_id` (same "seeing/acting on your
 * own data never needs a permission grant" pattern as
 * MyProfileController/MyDayController elsewhere in this codebase).
 */
class CustodyTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('assets.custody.view');
    }

    public function view(User $user, CustodyTransaction $transaction): bool
    {
        return $transaction->organization_id === $user->organization_id
            && ($user->can('assets.custody.view') || $this->isCurrentHolder($user, $transaction));
    }

    public function manage(User $user, CustodyTransaction $transaction): bool
    {
        return $transaction->organization_id === $user->organization_id
            && $user->can('assets.custody.manage');
    }

    public function confirmReceipt(User $user, CustodyTransaction $transaction): bool
    {
        return $transaction->organization_id === $user->organization_id
            && ($user->can('assets.custody.manage') || $this->isCurrentHolder($user, $transaction));
    }

    public function requestReturn(User $user, CustodyTransaction $transaction): bool
    {
        return $transaction->organization_id === $user->organization_id
            && ($user->can('assets.custody.manage') || $this->isCurrentHolder($user, $transaction));
    }

    private function isCurrentHolder(User $user, CustodyTransaction $transaction): bool
    {
        if ($transaction->receiving_employee_id === null) {
            return false;
        }

        return Employee::query()
            ->where('id', $transaction->receiving_employee_id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
