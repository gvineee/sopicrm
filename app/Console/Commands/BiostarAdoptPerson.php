<?php

namespace App\Console\Commands;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\AdoptBiostarPersonAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Says, on a human's behalf, "the person BioStar just gave us is somebody we
 * already have" — and merges the two records.
 *
 * This exists as a deliberate, separate step because the sync cannot make the
 * judgement. On this install the same person is „ირაკლი ღვინერია" in the CRM
 * and `irakli gvineria` in BioStar: one human in two scripts, which no name
 * comparison should be trusted to equate, while a confident match on two
 * genuinely different people who share a name would attribute one person's
 * hours to another.
 */
class BiostarAdoptPerson extends Command
{
    protected $signature = 'biostar:adopt-person
        {--organization= : ორგანიზაციის UUID}
        {--biostar-id= : BioStar-ის user id, რომელიც ჩამოვიდა ვერიფიკაციის მოლოდინში}
        {--employee= : არსებული თანამშრომლის internal_code, რომელსაც უნდა მიება}
        {--actor= : ვისი სახელით ჩაიწეროს აუდიტში (email)}
        {--apply : ცვლილების რეალურად შესრულება (ნაგულისხმევად მხოლოდ ნაჩვენებია)}';

    protected $description = 'BioStar-იდან ჩამოსული დროებითი ჩანაწერის მიბმა უკვე არსებულ თანამშრომელზე.';

    public function handle(AdoptBiostarPersonAction $action): int
    {
        $organization = Organization::query()->find((string) $this->option('organization'));

        if ($organization === null) {
            $this->error('მიუთითეთ არსებული --organization=<uuid>.');

            return self::FAILURE;
        }

        CurrentOrganization::set($organization->id);
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Console has no HTTP middleware to set the tenant GUC that
            // row-level security reads, so without this the queries below see
            // an empty table and report that nothing matched.
            DB::statement("select set_config('app.current_org_id', ?, false)", [$organization->id]);
        }

        $provisional = Employee::query()
            ->where('biostar_user_id', (string) $this->option('biostar-id'))
            ->first();

        $existing = Employee::query()
            ->where('internal_code', (string) $this->option('employee'))
            ->first();

        if ($provisional === null || $existing === null) {
            $this->error('ვერ მოიძებნა: '
                .($provisional === null ? 'BioStar-ის ჩანაწერი ' : '')
                .($existing === null ? 'არსებული თანამშრომელი' : ''));

            return self::FAILURE;
        }

        $actor = User::query()->where('email', (string) $this->option('actor'))->first();

        if ($actor === null) {
            $this->error('მიუთითეთ --actor=<email>: შერწყმა ადამიანის გადაწყვეტილებაა და აუდიტში უნდა ჩაიწეროს.');

            return self::FAILURE;
        }

        $this->line('  '.$this->describe($provisional).'  →  '.$this->describe($existing));

        if (! $this->option('apply')) {
            $this->warn('ეს იყო მხოლოდ ჩვენება. შესასრულებლად დაამატეთ --apply');

            return self::SUCCESS;
        }

        try {
            $merged = $action->execute($provisional, $existing, $actor);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info('შერწყმულია: '.$this->describe($merged));
        $this->line('ბარათი, გატარებების ისტორია და BioStar-ის იდენტიფიკატორი ახლა ამ თანამშრომელზეა.');

        return self::SUCCESS;
    }

    private function describe(Employee $employee): string
    {
        return trim($employee->first_name.' '.$employee->last_name)
            ." [{$employee->internal_code}, biostar={$employee->biostar_user_id}, {$employee->status}]";
    }
}
