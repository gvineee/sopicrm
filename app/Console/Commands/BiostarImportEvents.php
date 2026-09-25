<?php

namespace App\Console\Commands;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\IngestRawAccessEventAction;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Services\BiostarReadClient;
use App\Domain\Devices\Support\BiostarEventTaxonomy;
use App\Domain\Shared\Services\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backfills real badge reads out of BioStar's event log and into the CRM.
 *
 * The device-connector service is the ongoing path; this is the one-off that
 * brings across history the connector was not running for, and the way a new
 * install proves the read path end to end against the real server. Both go
 * through the SAME `IngestRawAccessEventAction` and the same taxonomy, so a
 * backfilled swipe is stored identically to a live one — no second, drifting
 * interpretation of what a BioStar event means.
 *
 * Defaults to a dry run, and re-running it is safe: the ingest deduplicates on
 * (organization, device, native event id, stream epoch).
 */
class BiostarImportEvents extends Command
{
    protected $signature = 'biostar:import-events
        {--organization= : ორგანიზაციის UUID}
        {--since= : საიდან (მაგ. 2026-09-01 ან -7 days). ნაგულისხმევად ბოლო 7 დღე}
        {--limit=500 : მაქსიმუმ რამდენი ჩანაწერი}
        {--quiet-table : ცალკეული ჩანაწერების ცხრილის გარეშე (ავტომატური გაშვებისთვის)}
        {--apply : ცვლილების რეალურად შესრულება (ნაგულისხმევად მხოლოდ ნაჩვენებია)}';

    protected $description = 'BioStar-ის ჟურნალიდან რეალური გატარებების ჩამოტანა (მხოლოდ კითხულობს BioStar-იდან).';

    public function handle(BiostarReadClient $biostar, IngestRawAccessEventAction $ingest): int
    {
        $organization = $this->resolveOrganization();

        if ($organization === null) {
            return self::FAILURE;
        }

        return $this->importFor($organization, $biostar, $ingest);
    }

    /**
     * One BioStar server serves one organization. Its readers are physical
     * hardware at one company's gates, so this deliberately never fans out
     * across every tenant: that would create a second copy of the same
     * physical door per organization and attribute one company's staff
     * movements to another.
     */
    private function resolveOrganization(): ?Organization
    {
        $id = (string) ($this->option('organization') ?: config('devices.biostar.organization_id', ''));

        if ($id !== '') {
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

        $this->error('მიუთითეთ --organization=<uuid> ან BIOSTAR_ORGANIZATION_ID. ხელმისაწვდომი:');
        foreach ($organizations as $organization) {
            $this->line("  {$organization->id}  {$organization->name}");
        }

        return null;
    }

    private function importFor(
        Organization $organization,
        BiostarReadClient $biostar,
        IngestRawAccessEventAction $ingest,
    ): int {
        CurrentOrganization::set($organization->id);
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Console has no HTTP middleware to set the tenant GUC that
            // row-level security reads; without it every lookup below sees an
            // empty database and this command would cheerfully re-create rows
            // that already exist.
            DB::statement("select set_config('app.current_org_id', ?, false)", [$organization->id]);
        }

        try {
            $devices = $this->fetchDevices($biostar);
            $cards = $biostar->cardsByUserId();
            $rows = $this->fetchEvents($biostar);
        } catch (Throwable $exception) {
            $this->error('BioStar-თან დაკავშირება ვერ მოხერხდა: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($devices === []) {
            $this->error('BioStar-ში წამკითხველი ვერ მოიძებნა.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        // The site the readers stand on, not the server's clock: the times in
        // the table below are meant to be the ones somebody at the gate would
        // have seen. An organization has no timezone of its own, so where no
        // site exists yet the architecture default applies.
        $siteTimezone = Site::query()->value('timezone');
        $timezone = is_string($siteTimezone) && $siteTimezone !== '' ? $siteTimezone : 'Asia/Tbilisi';

        /** @var array<string, Device|null> $deviceModels */
        $deviceModels = [];

        foreach ($devices as $serial => $name) {
            $deviceModels[$serial] = $apply
                ? $this->resolveDevice($organization->id, $serial, $name, $timezone)
                : Device::query()->where('serial_number', $serial)->first();

            $this->line("  წამკითხველი {$serial} — {$name}"
                .($apply || $deviceModels[$serial] !== null ? '' : '  (დაემატება)'));
        }

        $table = [];
        $imported = 0;

        foreach ($rows as $row) {
            $serial = is_array($row['device_id'] ?? null) ? (string) ($row['device_id']['id'] ?? '') : '';
            $device = $deviceModels[$serial] ?? null;
            $userRef = is_array($row['user_id'] ?? null) && isset($row['user_id']['user_id'])
                ? (string) $row['user_id']['user_id']
                : null;
            $card = $userRef !== null ? ($cards[$userRef] ?? null) : null;
            $code = is_array($row['event_type_id'] ?? null) ? ($row['event_type_id']['code'] ?? null) : null;
            $door = is_array($row['door_id'][0] ?? null) ? (string) ($row['door_id'][0]['name'] ?? '') : '';
            $serverTime = isset($row['server_datetime']) ? (string) $row['server_datetime'] : null;
            $deviceTime = (string) ($row['datetime'] ?? '');

            $eventData = [
                'native_event_id' => (int) $row['id'],
                // BioStar 2's own event log is durably unique and monotonic —
                // it absorbs device-side log rollover internally, so there is
                // no separate stream epoch to track on this path.
                'stream_epoch' => 0,
                'raw_device_time' => $deviceTime,
                'server_time' => $serverTime,
                'clock_offset_seconds' => $this->clockOffsetSeconds($deviceTime, $serverTime),
                'external_user_ref' => $userRef,
                'event_code' => BiostarEventTaxonomy::eventCodeFor(is_scalar($code) ? (string) $code : null),
                'event_subcode' => is_scalar($code) ? (string) $code : null,
                'card_type' => $card['card_type'] ?? null,
                'card_hex' => $card['card_hex'] ?? null,
                'payload' => [
                    'biostar_event_id' => (string) $row['id'],
                    'biostar_user_id' => $userRef,
                    'door' => $door !== '' ? $door : null,
                ],
                'ingestion_source' => 'biostar-backfill',
            ];

            if ($apply && $device !== null) {
                // The ingest hands back the row it already had when this event
                // has been seen before, so this counts what actually arrived
                // rather than how many rows BioStar was asked about — on a
                // re-run those two numbers are very different, and reporting
                // the larger one would make a no-op look like an import.
                $imported += $ingest->execute($device, $eventData)->wasRecentlyCreated ? 1 : 0;
            }

            $table[] = [
                (string) $row['id'],
                CarbonImmutable::parse($serverTime ?? $deviceTime)->setTimezone($timezone)->format('Y-m-d H:i'),
                $door !== '' ? $door : '—',
                $userRef ?? '—',
                $card['card_decimal'] ?? '—',
                $eventData['event_code'],
            ];
        }

        if ($table === []) {
            $this->info('ამ პერიოდში ჩანაწერი არ დაფიქსირებულა.');

            return self::SUCCESS;
        }

        if (! $this->option('quiet-table')) {
            $this->table(['BioStar ID', 'დრო (ადგილობრივი)', 'კარი', 'BioStar user', 'ბარათი', 'ტიპი'], $table);
        }

        if (! $apply) {
            $this->warn('ეს იყო მხოლოდ ჩვენება. ჩამოსატანად დაამატეთ --apply');

            return self::SUCCESS;
        }

        $seen = count($table);
        $this->info("{$imported} ახალი ჩანაწერი ჩამოტანილია ({$seen}-დან; დანარჩენი უკვე ბაზაში იყო).");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function fetchDevices(BiostarReadClient $biostar): array
    {
        $devices = [];

        foreach ($biostar->get('/api/devices', [], 'DeviceCollection.rows') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');

            if ($id !== '') {
                $devices[$id] = (string) ($row['name'] ?? $id);
            }
        }

        return $devices;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchEvents(BiostarReadClient $biostar): array
    {
        $since = CarbonImmutable::parse((string) ($this->option('since') ?: '-7 days'))->utc();
        $limit = (int) $this->option('limit');

        // Deliberately NOT filtered server-side. BioStar's only date condition
        // matches on `datetime`, the time the READER believed it was — and on
        // this install that clock runs hours behind. Asking the server for
        // "since the 18th" returned 401 of 451 events whose real times were
        // all inside the window: fifty real badge reads silently dropped at
        // the boundary because the device disagreed about what day it was.
        // The window is therefore applied here, against `server_datetime`,
        // which is the same trustworthy time the ingest stores.
        $rows = [];

        foreach ($biostar->search('/api/events/search', [
            'Query' => [
                'limit' => $limit,
                'orders' => [['column' => 'datetime', 'descending' => true]],
            ],
        ], 'EventCollection.rows') as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $at = $row['server_datetime'] ?? $row['datetime'] ?? null;

            if (is_string($at) && CarbonImmutable::parse($at)->utc()->greaterThanOrEqualTo($since)) {
                $rows[] = $row;
            }
        }

        if (count($rows) >= $limit) {
            $this->warn("დაბრუნდა {$limit} ჩანაწერი — ეს ლიმიტია და უფრო ძველი შესაძლოა გამორჩეს. გაზარდეთ --limit.");
        }

        // Sorted by BioStar's own id so the ingest checkpoint advances
        // monotonically and a genuine gap is still reported as one.
        usort($rows, fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    /**
     * How far the reader's own clock is from the server's. Whole seconds: the
     * question is whether a device is minutes or hours out, and a fractional
     * part would only ever be noise from the API's second-resolution strings.
     */
    private function clockOffsetSeconds(string $deviceTime, ?string $serverTime): ?int
    {
        if ($deviceTime === '' || $serverTime === null || $serverTime === '') {
            return null;
        }

        return (int) round(CarbonImmutable::parse($deviceTime)->diffInSeconds(CarbonImmutable::parse($serverTime), false));
    }

    private function resolveDevice(string $organizationId, string $serial, string $name, string $timezone): Device
    {
        $device = Device::query()->where('serial_number', $serial)->first();

        if ($device !== null) {
            return $device;
        }

        $site = Site::query()->first() ?? Site::query()->create([
            'organization_id' => $organizationId,
            'name' => 'ობიექტი',
            'timezone' => $timezone,
            'is_active' => true,
        ]);

        return Device::query()->create([
            'organization_id' => $organizationId,
            'site_id' => $site->id,
            'name' => $name,
            'vendor' => 'suprema',
            'serial_number' => $serial,
            'device_identifier' => $serial,
            'model' => 'XPass 2',
            // Left unspecified on purpose. Both doors on this install have
            // `exit_device: NONE`, so BioStar itself does not know which
            // reader is an entry and which an exit — inventing a direction
            // here would silently decide who was "in" all day.
            'reader_role' => 'unspecified',
            'device_timezone' => $timezone,
            'timezone' => $timezone,
            // `unknown`, not `online`: the CRM has never heard from this
            // reader directly. Its events reach us through BioStar's log, and
            // claiming a live connection we do not have would make a silent
            // connector look healthy. App\Domain\Devices\Services\DeviceStatusResolver
            // sets the real value once a heartbeat arrives.
            'status' => 'unknown',
            'enabled' => true,
        ]);
    }
}
