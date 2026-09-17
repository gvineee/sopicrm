<?php

namespace App\Domain\Payroll\Exceptions;

/**
 * spec section 3: "საკუთარი ტაბელის ან საკუთარი ფინანსური მოთხოვნის
 * საბოლოო დამტკიცება ნაგულისხმევად აკრძალულია. მცირე კომპანიის
 * გამონაკლისი მხოლოდ მფლობელის ცალკე უფლებით და აუდიტით." Raised when the
 * approving user has their OWN PayRunLine inside the PayRun being approved
 * and lacks the separate `payroll.pay-runs.approve-own` permission (never
 * granted by the seeder — an owner grants it by hand, per-user, as a
 * deliberate, audited exception).
 */
class SelfApprovalNotAllowedException extends PayrollDomainException
{
    public function __construct()
    {
        parent::__construct(
            'A user may not approve a PayRun that includes their own PayRunLine without the separate approve-own permission.',
            'self_approval_forbidden',
            403,
            ['approver_user_id' => ['საკუთარი ანაზღაურების დამტკიცება ნაგულისხმევად აკრძალულია.']],
        );
    }
}
