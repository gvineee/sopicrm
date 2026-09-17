<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Exceptions\InvalidPayRunStateException;
use App\Domain\Payroll\Models\PayAdjustment;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Support\Money;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * spec section 8 hard rule: "დაზიანებული ხელსაწყოს, ჯარიმის ან ვალის
 * ავტომატური ჩამოჭრა აკრძალულია. ასეთი კორექტირება ცალკე უფლებას, მიზეზს
 * და კომპანიის დამტკიცებულ წესს მოითხოვს." Deduction-type categories (and
 * any signed-negative amount under `other`) may only be created by a caller
 * whose Policy check already confirmed the separate
 * `payroll.adjustments.create-deduction` permission — this Action re-checks
 * the type/sign pairing itself as the last line of defense, and NOTHING in
 * this codebase creates a PayAdjustment automatically from a job/event.
 *
 * "დამტკიცებული პერიოდის გასწორება ხდება reversal/adjustment-ით და არა
 * delete-ით" (spec section 8): this is that one mechanism, for a PayRun at
 * ANY post-calculation status including `locked` — it never edits an
 * existing PayRunLine's own gross_amount, only adds a new adjustment row and
 * recomputes the line's derived `adjustments_amount`/`net_amount`.
 */
class CreatePayAdjustmentAction
{
    /**
     * @var list<string>
     */
    public const DEDUCTION_TYPES = ['absence_deduction', 'damaged_tool_deduction', 'penalty'];

    /**
     * @var list<string>
     */
    public const ADDITION_TYPES = ['overtime', 'holiday', 'bonus', 'vacation'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        PayRunLine $line,
        string $type,
        string $amount,
        string $reason,
        User $actor,
        ?string $reversesPayAdjustmentId = null,
    ): PayAdjustment {
        $payRun = $line->payRun;

        if (! in_array($payRun->status, ['calculated', 'reviewed', 'approved', 'locked'], true)) {
            throw new InvalidPayRunStateException($payRun->status, 'calculated, reviewed, approved, or locked', 'add a pay adjustment');
        }

        $isDeduction = in_array($type, self::DEDUCTION_TYPES, true) || Money::isNegative($amount);

        if (in_array($type, self::ADDITION_TYPES, true) && Money::isNegative($amount)) {
            throw new InvalidArgumentException("Adjustment type [{$type}] must have a non-negative amount.");
        }

        if (in_array($type, self::DEDUCTION_TYPES, true) && ! Money::isNegative($amount)) {
            throw new InvalidArgumentException("Adjustment type [{$type}] must have a negative amount.");
        }

        return DB::transaction(function () use ($line, $type, $amount, $reason, $actor, $reversesPayAdjustmentId, $isDeduction) {
            $adjustment = PayAdjustment::query()->create([
                'pay_run_line_id' => $line->id,
                'employee_id' => $line->employee_id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'approved_by_user_id' => $actor->id,
                'requires_separate_permission' => $isDeduction,
                'reverses_pay_adjustment_id' => $reversesPayAdjustmentId,
            ]);

            $lineBefore = $line->only(['adjustments_amount', 'net_amount']);

            $line->adjustments_amount = Money::add((string) $line->adjustments_amount, $amount, 2);
            $line->net_amount = Money::add((string) $line->gross_amount, (string) $line->adjustments_amount, 2);
            $line->save();

            $this->auditLogger->log(
                action: 'payroll.pay_adjustment.created',
                target: $adjustment,
                before: null,
                after: $adjustment->only(['type', 'amount', 'reason', 'pay_run_line_id']),
                reason: $reason,
                actor: $actor,
            );

            $this->auditLogger->log(
                action: 'payroll.pay_run_line.adjustments_recomputed',
                target: $line,
                before: $lineBefore,
                after: $line->only(['adjustments_amount', 'net_amount']),
                actor: $actor,
            );

            return $adjustment;
        });
    }
}
