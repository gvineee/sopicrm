<?php

namespace App\Domain\DailyJournal\Exceptions;

/**
 * Spec section 3: "საკუთარი ტაბელის ან საკუთარი ფინანსური მოთხოვნის
 * საბოლოო დამტკიცება ნაგულისხმევად აკრძალულია." Applied here to daily
 * journal acceptance too: the user who submitted a report may not also be
 * the one who accepts it, except via the owner's separately-permissioned,
 * audited exception (spec: "მცირე კომპანიის გამონაკლისი მხოლოდ მფლობელის
 * ცალკე უფლებით და აუდიტით") — see AcceptDailyReportAction.
 */
class SelfApprovalNotAllowedException extends DailyReportDomainException
{
    public function __construct()
    {
        parent::__construct('თქვენ ვერ დაამტკიცებთ საკუთარი მიერ წარდგენილ ჟურნალს.');
    }
}
