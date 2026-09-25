<?php

namespace App\Console\Commands;

use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Audit A26. Seven seeded demo accounts were live on a publicly reachable
 * instance, all holding the `owner` role and all authenticating with the
 * literal password `password`.
 *
 * Deactivating is deliberately the only thing this does. Deleting a user row
 * would take its audit trail's actor with it, and those accounts are named as
 * the actor on real rows created while testing; `is_active = false` stops the
 * login and keeps the history readable. It is also reversible, which matters
 * when the judgement "this account is not real" was made from an email domain.
 *
 * The owner's own account is never touched — it is identified by NOT being on
 * a demo domain, so there is no list of exceptions to keep in step.
 */
class SecurityDisableDemoAccounts extends Command
{
    protected $signature = 'security:disable-demo-accounts {--apply : ცვლილების რეალურად შესრულება (ნაგულისხმევად მხოლოდ ნაჩვენებია)}';

    protected $description = 'გამორთავს სატესტო/დემო დომენის ანგარიშებს (ნაგულისხმევად მხოლოდ აჩვენებს).';

    /** Addresses that only ever come from seeded demo or test data. */
    private const DEMO_DOMAINS = ['example.com', 'example.test', 'example.org'];

    public function handle(AuditLogger $auditLogger): int
    {
        $targets = User::query()
            ->where('is_active', true)
            ->get()
            ->filter(function (User $user): bool {
                foreach (self::DEMO_DOMAINS as $domain) {
                    if (str_ends_with(strtolower((string) $user->email), '@'.$domain)) {
                        return true;
                    }
                }

                return false;
            });

        if ($targets->isEmpty()) {
            $this->info('აქტიური დემო ანგარიში ვერ მოიძებნა.');

            return self::SUCCESS;
        }

        $this->table(
            ['ელფოსტა', 'სახელი', 'ორგანიზაცია'],
            $targets->map(fn (User $user) => [$user->email, $user->name, $user->organization_id])->all(),
        );

        if (! $this->option('apply')) {
            $this->warn('ეს იყო მხოლოდ ჩვენება. რეალურად გასამორთად: php artisan security:disable-demo-accounts --apply');

            return self::SUCCESS;
        }

        foreach ($targets as $user) {
            $user->is_active = false;
            $user->save();

            $auditLogger->log(
                action: 'auth.user.deactivated',
                target: $user,
                before: ['is_active' => true],
                after: ['is_active' => false],
                reason: 'Audit A26: სატესტო/დემო ანგარიში საჯაროდ ხელმისაწვდომ ინსტანციაზე.',
                actorLabel: 'console:security:disable-demo-accounts',
                organizationId: $user->organization_id,
            );
        }

        $this->info($targets->count().' ანგარიში გამოირთო. ჩანაწერები და ისტორია ხელუხლებელია.');
        $this->line('უკან დასაბრუნებლად: იმავე ანგარიშს დაუბრუნეთ is_active = true.');

        return self::SUCCESS;
    }
}
