<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Audit A26: „სატესტო და რეალური მონაცემების გამიჯვნა გამოშვებამდე
 * გადასამოწმებელია."
 *
 * Checking that by hand found something worse than stale demo rows: every
 * account in the running database, including the owner's own, authenticated
 * with the literal password `password` — because they were all created by
 * UserFactory, which hashes exactly that. `APP_ENV` was `local`, so the
 * seeder's "not in production" guard was satisfied on paper, while the same
 * instance was being served to the internet through a tunnel.
 *
 * This command exists so that question can be answered in one line instead of
 * by writing a throwaway script. It only ever READS: it names what is wrong
 * and who has to fix it, and changes nothing, because rotating someone's
 * password without telling them locks them out of their own system.
 */
class SecurityCheckAccounts extends Command
{
    protected $signature = 'security:check-accounts';

    protected $description = 'აღწერს სუსტი პაროლის, დუბლირებული ელფოსტისა და სატესტო ანგარიშების პრობლემებს (მხოლოდ კითხულობს).';

    /**
     * Passwords a seeder, a factory or a hurried first login typically leaves
     * behind. The list is deliberately short: this is a check for known
     * defaults, not a password cracker.
     */
    private const KNOWN_DEFAULTS = ['password', 'Password1!', 'secret', '12345678', 'password123', 'admin', 'demo', 'test'];

    /** Addresses that only ever come from seeded demo or test data. */
    private const DEMO_DOMAINS = ['example.com', 'example.test', 'example.org'];

    public function handle(): int
    {
        $findings = 0;

        $this->line('');
        $this->info('ანგარიშების შემოწმება — '.config('app.url').' ('.app()->environment().')');
        $this->line('');

        $weak = [];
        $demo = [];

        // User is deliberately outside BelongsToOrganization (see that model),
        // so this already sees every account rather than one tenant's.
        foreach (User::query()->get() as $user) {
            foreach (self::KNOWN_DEFAULTS as $guess) {
                if (Hash::check($guess, $user->password)) {
                    $weak[] = [$user->email, $guess, $user->is_active ? 'აქტიური' : 'გამორთული'];

                    break;
                }
            }

            foreach (self::DEMO_DOMAINS as $domain) {
                if (str_ends_with(strtolower((string) $user->email), '@'.$domain)) {
                    $demo[] = [$user->email, $user->name, $user->is_active ? 'აქტიური' : 'გამორთული'];

                    break;
                }
            }
        }

        if ($weak !== []) {
            $findings++;
            $this->error('ცნობილი ნაგულისხმევი პაროლი — '.count($weak).' ანგარიში:');
            $this->table(['ელფოსტა', 'პაროლი', 'მდგომარეობა'], $weak);
            $this->line('  → თითოეულმა მფლობელმა თავად უნდა შეცვალოს პაროლი; სხვისი პაროლის შეცვლა მას სისტემიდან კეტავს.');
            $this->line('');
        }

        if ($demo !== []) {
            $findings++;
            $this->warn('სატესტო/დემო დომენის ანგარიში — '.count($demo).':');
            $this->table(['ელფოსტა', 'სახელი', 'მდგომარეობა'], $demo);
            $this->line('  → რეალურ გარემოში ეს ანგარიშები გამორთული ან წაშლილი უნდა იყოს.');
            $this->line('');
        }

        // `users` is unique on (organization_id, email), not on email alone,
        // so one address in two organizations is allowed by design. The
        // consequence is still worth surfacing: signing in with an address
        // alone no longer says which account is meant.
        $duplicated = DB::table('users')
            ->select('email', DB::raw('count(*) as total'))
            ->groupBy('email')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicated->isNotEmpty()) {
            $findings++;
            $this->warn('ერთი ელფოსტა რამდენიმე ორგანიზაციის ანგარიშზე:');
            $this->table(
                ['ელფოსტა', 'ანგარიშების რაოდენობა'],
                $duplicated->map(fn ($row) => [$row->email, $row->total])->all(),
            );
            $this->line('  → სქემით დაშვებულია, მაგრამ ელფოსტით შესვლა ვეღარ ამბობს, რომელი ანგარიში იგულისხმება.');
            $this->line('');
        }

        if ($findings === 0) {
            $this->info('პრობლემა ვერ მოიძებნა.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->error("ნაპოვნია {$findings} სახის პრობლემა. ეს ბრძანება მხოლოდ კითხულობს — არაფერი შეცვლილა.");

        return self::FAILURE;
    }
}
