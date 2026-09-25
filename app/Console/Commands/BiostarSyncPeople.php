<?php

namespace App\Console\Commands;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\SyncBiostarPersonAction;
use App\Domain\Devices\Services\BiostarReadClient;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Brings the people enrolled in BioStar across into the CRM, where they land
 * as `pending_verification` until somebody with the authority assigns a
 * department and vouches for them.
 *
 * Read-only against BioStar: it logs in and reads. It never creates a BioStar
 * user, issues or revokes a card, changes an access group or opens a door —
 * this phase has BioStar owning devices, cards and access, and the CRM only
 * reading. It also never creates a login on our side: an account is granted
 * deliberately, by invite or by linking an existing one, never as a side
 * effect of somebody being handed a card.
 *
 * Defaults to a dry run. A command that changes the roster the first time
 * somebody tries it is not one anybody should point at a production database.
 */
class BiostarSyncPeople extends Command
{
    protected $signature = 'biostar:sync-people
        {--organization= : რომელი ორგანიზაციის თანამშრომლებად ჩაიწეროს (UUID)}
        {--apply : ცვლილების რეალურად შესრულება (ნაგულისხმევად მხოლოდ ნაჩვენებია)}';

    protected $description = 'BioStar-ში დარეგისტრირებული ადამიანების ჩამოტანა ვერიფიკაციის მოლოდინში (მხოლოდ კითხულობს BioStar-იდან).';

    public function handle(BiostarReadClient $biostar, SyncBiostarPersonAction $action): int
    {
        $organization = $this->resolveOrganization();

        if ($organization === null) {
            return self::FAILURE;
        }

        CurrentOrganization::set($organization->id);
        if (DB::connection()->getDriverName() === 'pgsql') {
            // A console context has no HTTP middleware to set the tenant GUC
            // that row-level security checks, so it is set here directly.
            // Without it every lookup below sees an empty table, and the
            // command reports somebody as new who is already on the roster.
            DB::statement("select set_config('app.current_org_id', ?, false)", [$organization->id]);
        }

        try {
            $people = $this->fetchPeople($biostar);
        } catch (Throwable $exception) {
            $this->error('BioStar-თან დაკავშირება ვერ მოხერხდა: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($people === []) {
            $this->info('BioStar-ში ჩამოსატანი ადამიანი ვერ მოიძებნა.');

            return self::SUCCESS;
        }

        $rows = [];
        $applied = 0;

        foreach ($people as $person) {
            $known = Employee::query()->where('biostar_user_id', $person['user_id'])->first();

            if ($this->option('apply')) {
                ['created' => $created] = $action->execute($person, null);
                $applied += $created ? 1 : 0;
            }

            $rows[] = [
                $person['user_id'],
                $person['name'] ?? '—',
                $person['card_id'] ?? '—',
                $known !== null ? "უკვე არის ({$known->status})" : ($this->option('apply') ? 'დაემატა' : 'დაემატება'),
            ];
        }

        $this->table(['BioStar ID', 'სახელი', 'ბარათი', 'მდგომარეობა'], $rows);
        $this->line('');
        $this->info("ორგანიზაცია: {$organization->name}");

        if (! $this->option('apply')) {
            $this->warn('ეს იყო მხოლოდ ჩვენება. რეალურად ჩამოსატანად დაამატეთ --apply');

            return self::SUCCESS;
        }

        $this->info("{$applied} ახალი ადამიანი დაემატა, სტატუსით „ვერიფიკაციის მოლოდინში\".");
        $this->line('დეპარტამენტისა და უფლებების მინიჭებამდე ისინი დავალებებში ვერ მონაწილეობენ.');

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $id = $this->option('organization');

        if (is_string($id) && $id !== '') {
            $organization = Organization::query()->find($id);

            if ($organization === null) {
                $this->error('ასეთი ორგანიზაცია ვერ მოიძებნა.');
            }

            return $organization;
        }

        $organizations = Organization::query()->orderBy('name')->get();

        if ($organizations->count() === 1) {
            return $organizations->first();
        }

        $this->error('მიუთითეთ ორგანიზაცია --organization=<uuid>. ხელმისაწვდომი:');
        foreach ($organizations as $organization) {
            $this->line("  {$organization->id}  {$organization->name}");
        }

        return null;
    }

    /**
     * @return list<array{user_id: string, name: string|null, phone: string|null, card_id: string|null, card_type: string|null}>
     */
    private function fetchPeople(BiostarReadClient $biostar): array
    {
        /** @var list<string> $ignored */
        $ignored = config('devices.biostar.ignored_user_ids', ['1']);
        $cards = $biostar->cardsByUserId();
        $people = [];

        foreach ($biostar->get('/api/users', ['limit' => 1000], 'UserCollection.rows') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $userId = (string) ($row['user_id'] ?? '');

            // BioStar's own built-in administrator is an operator login for
            // the access system, not somebody who works here.
            if ($userId === '' || in_array($userId, $ignored, true)) {
                continue;
            }

            $card = $cards[$userId] ?? null;

            $people[] = [
                'user_id' => $userId,
                'name' => isset($row['name']) ? (string) $row['name'] : null,
                'phone' => isset($row['phone']) && $row['phone'] !== '' ? (string) $row['phone'] : null,
                'card_id' => $card['card_decimal'] ?? null,
                'card_type' => $card['card_type'] ?? null,
            ];
        }

        return $people;
    }
}
