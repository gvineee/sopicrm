<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Models\Payment;
use App\Domain\Payroll\Models\PaymentAllocation;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Services\PayrollBalanceService;
use App\Domain\Payroll\Support\CsvFormulaGuard;
use App\Domain\Payroll\Support\Money;

/**
 * REQ-PAY-12: a real, downloadable CSV of one PayRun's per-employee
 * financial detail — period, employee, hours, days, accrual (this run's own
 * net earnings), advance (deducted against this run), paid (actual Payment
 * rows recorded against this run), balance (the employee's real, current
 * running balance per PayrollBalanceService — the same trusted computation
 * used everywhere else in this module, never a second, parallel one).
 *
 * Every free-text-originated cell (the employee's own name) is passed
 * through CsvFormulaGuard::sanitize() — see that class's docblock for the
 * full spreadsheet-formula-injection rationale (REQ-PAY-12's own explicit
 * hardening requirement). Amount/date/period columns are values this
 * Action itself formats from Decimal/Carbon data, never raw user text, so
 * they are not user-controlled and do not need the same guard — but see
 * CsvFormulaGuard's own docblock before assuming that of a new column you
 * add later.
 */
class ExportPayRunCsvAction
{
    public function __construct(private readonly PayrollBalanceService $balances) {}

    public function execute(PayRun $payRun): string
    {
        $payRun->loadMissing(['payPeriod', 'lines.employee']);

        $periodLabel = $payRun->payPeriod !== null
            ? $payRun->payPeriod->starts_on->toDateString().' – '.$payRun->payPeriod->ends_on->toDateString()
            : '';

        $lines = $payRun->lines;
        $byEmployee = $lines->groupBy('employee_id');

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open a temporary stream for the pay run CSV export.');
        }

        // UTF-8 BOM: without it, Excel on Windows (a realistic real-world
        // opener for a Georgian-language payroll export) misdetects the
        // encoding and renders Georgian text as mojibake — a real,
        // practical correctness issue for this export's actual audience,
        // not just a nicety.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, ['პერიოდი', 'თანამშრომელი', 'საათები', 'დღეები', 'დარიცხვა', 'ავანსი', 'გადახდილი', 'ნაშთი']);

        foreach ($byEmployee as $employeeId => $employeeLines) {
            $employee = $employeeLines->first()->employee;
            $employeeName = $employee !== null
                ? trim($employee->first_name.' '.$employee->last_name)
                : 'უცნობი თანამშრომელი';

            $hours = $employeeLines->where('basis', 'hourly')
                ->reduce(fn (string $carry, $line) => Money::add($carry, (string) $line->quantity, 2), '0');

            $days = $employeeLines->where('basis', 'daily')
                ->reduce(fn (string $carry, $line) => Money::add($carry, (string) $line->quantity, 2), '0');

            $accrual = $employeeLines
                ->reduce(fn (string $carry, $line) => Money::add($carry, (string) $line->net_amount, 2), '0');

            $advanceDeducted = PaymentAllocation::query()
                ->where('allocation_type', 'advance_deduction')
                ->whereHas('payment', fn ($q) => $q->where('pay_run_id', $payRun->id)->where('employee_id', $employeeId))
                ->pluck('allocated_amount')
                ->reduce(fn (string $carry, mixed $v) => Money::add($carry, (string) $v, 2), '0');

            // spec section 8 hard rule: "pending გადახდა paid არ ჩაითვლოს"
            // (Payment::class's own docblock) — only a real, completed
            // payment counts toward this column.
            $paid = Payment::query()
                ->where('pay_run_id', $payRun->id)
                ->where('employee_id', $employeeId)
                ->where('status', 'completed')
                ->pluck('amount')
                ->reduce(fn (string $carry, mixed $v) => Money::add($carry, (string) $v, 2), '0');

            $balance = $this->balances->outstandingForEmployee((string) $employeeId);

            fputcsv($handle, [
                $periodLabel,
                CsvFormulaGuard::sanitize($employeeName),
                $hours,
                $days,
                $accrual,
                $advanceDeducted,
                $paid,
                $balance,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
