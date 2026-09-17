<?php

namespace App\Domain\Timesheets\Exceptions;

/**
 * spec section 3: "საკუთარი ტაბელის ... საბოლოო დამტკიცება ნაგულისხმევად
 * აკრძალულია. მცირე კომპანიის გამონაკლისი მხოლოდ მფლობელის ცალკე
 * უფლებით და აუდიტით." Enforced here (not just in the Policy) because the
 * Action is the last line of defense against a self-approval slipping
 * through a future second entry point into the same transition.
 */
class SelfApprovalNotAllowedException extends TimesheetDomainException
{
    public function __construct()
    {
        parent::__construct(
            'A user may not approve their own timesheet or adjustment request.',
            'self_approval_forbidden',
            403,
            ['approver_user_id' => ['საკუთარი ტაბელის/მოთხოვნის დამტკიცება ნაგულისხმევად აკრძალულია.']],
        );
    }
}
