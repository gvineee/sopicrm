<?php

namespace App\Policies;

use App\Domain\Shared\Models\AuditEvent;
use App\Models\User;

/**
 * Hard constraint: "no ordinary admin able to edit audit rows" / spec
 * section 21: "audit-ის რედაქტირება ჩვეულებრივი ადმინისტრატორისთვის
 * დაუშვებელია ... მაღალრისკიანი ჩანაწერებისთვის ცალკე დაცული
 * export/retention." `update`/`delete` return false unconditionally —
 * there is no permission that can ever make them true, by design, not by
 * omission.
 */
class AuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.events.view');
    }

    public function view(User $user, AuditEvent $auditEvent): bool
    {
        return $auditEvent->organization_id === $user->organization_id
            && $user->can('audit.events.view');
    }

    public function export(User $user): bool
    {
        return $user->can('audit.events.export');
    }

    public function update(User $user, AuditEvent $auditEvent): bool
    {
        return false;
    }

    public function delete(User $user, AuditEvent $auditEvent): bool
    {
        return false;
    }
}
