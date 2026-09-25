<?php

namespace App\Console\Commands;

use App\Domain\Devices\Services\BiostarReadClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Answers "is the reader's clock right?" from the only evidence BioStar's API
 * actually offers.
 *
 * There is no read-only endpoint for a device's current time — `/api/devices/
 * {id}/time` and `/api/devices/{id}/status` both answer "Request is not
 * supported", and `/api/server/datetime` answers "Permission Denied". Writing
 * the time is out of scope by design: this phase does not change BioStar
 * configuration.
 *
 * What is available is every event's pair of timestamps: `datetime`, the time
 * the READER believed it was, and `server_datetime`, BioStar's own trustworthy
 * UTC. The gap between them for a given device IS that device's clock error,
 * measured rather than assumed. The consequence is that this command can only
 * report on readers that have produced an event — after a clock is corrected,
 * it takes one badge read before the correction is visible here.
 */
class BiostarClockCheck extends Command
{
    protected $signature = 'biostar:clock-check {--sample=200 : რამდენი ბოლო მოვლენა შემოწმდეს}';

    protected $description = 'BioStar-ის წამკითხველების საათის გადახრის გაზომვა (მხოლოდ კითხულობს).';

    public function handle(BiostarReadClient $biostar): int
    {
        try {
            $names = $this->deviceNames($biostar);
            $rows = $biostar->search('/api/events/search', [
                'Query' => [
                    'limit' => (int) $this->option('sample'),
                    'orders' => [['column' => 'datetime', 'descending' => true]],
                ],
            ], 'EventCollection.rows');
        } catch (Throwable $exception) {
            $this->error('BioStar-თან დაკავშირება ვერ მოხერხდა: '.$exception->getMessage());

            return self::FAILURE;
        }

        /** @var array<string, array{latest: CarbonImmutable, offset: int, count: int}> $measured */
        $measured = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['datetime'], $row['server_datetime'])) {
                continue;
            }

            $serial = is_array($row['device_id'] ?? null) ? (string) ($row['device_id']['id'] ?? '') : '';

            if ($serial === '') {
                continue;
            }

            $server = CarbonImmutable::parse((string) $row['server_datetime']);
            $offset = (int) round(CarbonImmutable::parse((string) $row['datetime'])->diffInSeconds($server, false));

            // The NEWEST event per device is the current answer: an older one
            // describes a clock that may since have been corrected, and
            // averaging the two would hide the correction behind the fault.
            if (! isset($measured[$serial]) || $server->greaterThan($measured[$serial]['latest'])) {
                $measured[$serial] = ['latest' => $server, 'offset' => $offset, 'count' => 0];
            }

            $measured[$serial]['count']++;
        }

        if ($measured === []) {
            $this->warn('შემოწმებულ მონაკვეთში მოვლენა არ დაფიქსირებულა — გადახრის გაზომვა შეუძლებელია.');

            return self::SUCCESS;
        }

        $threshold = (int) config('devices.clock_drift_threshold_seconds', 300);
        $table = [];
        $anyDrift = false;

        foreach ($measured as $serial => $data) {
            $drift = abs($data['offset']) > $threshold;
            $anyDrift = $anyDrift || $drift;

            $table[] = [
                $serial,
                $names[$serial] ?? '—',
                $data['latest']->setTimezone('Asia/Tbilisi')->format('Y-m-d H:i:s'),
                sprintf('%+d წმ (%+.2f სთ)', $data['offset'], $data['offset'] / 3600),
                $drift ? 'გადახრილია' : 'გამართულია',
            ];
        }

        $this->table(['სერიული', 'სახელი', 'ბოლო მოვლენა', 'გადახრა', 'შეფასება'], $table);
        $this->line("ზღვარი: {$threshold} წმ. გაზომვა ბოლო მოვლენის მიხედვითაა — საათის გასწორების შემდეგ");
        $this->line('საჭიროა ერთი ახალი გატარება, რომ შედეგი აქ აისახოს.');

        return $anyDrift ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function deviceNames(BiostarReadClient $biostar): array
    {
        $names = [];

        foreach ($biostar->get('/api/devices', [], 'DeviceCollection.rows') as $row) {
            if (is_array($row) && isset($row['id'])) {
                $names[(string) $row['id']] = (string) ($row['name'] ?? $row['id']);
            }
        }

        return $names;
    }
}
