<?php

namespace App\Console\Commands;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Device;
use App\Domain\Notifications\Support\NotificationCreator;
use App\Domain\Notifications\Support\NotificationType;
use App\Domain\Notifications\Support\UsersWithPermission;
use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * QUEUE-01 (deferred remainder): catches a device/connector that has gone
 * entirely SILENT — no heartbeat at all in a while — which
 * `App\Domain\Devices\Actions\RecordDeviceHeartbeatAction`'s own device_fault
 * notification (NOTIFY-01) cannot catch by construction: that Action only
 * ever runs reactively, when a heartbeat DOES arrive and reports an explicit
 * offline/degraded status. If the connector process itself dies, no
 * heartbeat ever arrives, that Action never runs, and nothing would
 * otherwise notice. This command is the proactive half.
 *
 * Deliberately reuses the SAME `device_fault` notification type but a
 * DISTINCT dedup_key prefix ("device_fault:stale:...", vs the heartbeat
 * Action's "device_fault:{id}:{timestamp}") so the two triggers never
 * collide, and dedupes per calendar day — a device stuck stale for a week
 * gets one notification per day, not one per run of this command.
 */
class DevicesHealthCheck extends Command
{
    protected $signature = 'devices:health-check {--stale-minutes=30 : how long since the last heartbeat before a device counts as stale}';

    protected $description = 'Flag devices with no heartbeat in a while as stale, notifying whoever can see them.';

    public function handle(): int
    {
        $previousOrganizationId = CurrentOrganization::id();
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        $flagged = 0;

        try {
            $organizationIds = Organization::query()->pluck('id');
            $staleMinutes = (int) $this->option('stale-minutes');
            $threshold = Carbon::now()->subMinutes($staleMinutes);

            foreach ($organizationIds as $organizationId) {
                CurrentOrganization::set($organizationId);

                if ($isPgsql) {
                    DB::statement("select set_config('app.current_org_id', ?, false)", [$organizationId]);
                }

                $flagged += $this->flagStaleDevices($organizationId, $threshold);
            }
        } finally {
            CurrentOrganization::set($previousOrganizationId);

            if ($isPgsql) {
                DB::statement("select set_config('app.current_org_id', ?, false)", [$previousOrganizationId ?? '']);
            }
        }

        $this->info("Flagged {$flagged} stale device(s).");

        return self::SUCCESS;
    }

    private function flagStaleDevices(string $organizationId, Carbon $threshold): int
    {
        $staleDevices = Device::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', $threshold);
            })
            ->get();

        if ($staleDevices->isEmpty()) {
            return 0;
        }

        $recipients = UsersWithPermission::inOrganization($organizationId, 'devices.view');
        $today = Carbon::now()->toDateString();

        foreach ($staleDevices as $device) {
            foreach ($recipients as $recipient) {
                NotificationCreator::create(
                    recipient: $recipient,
                    type: NotificationType::DEVICE_FAULT,
                    title: 'მოწყობილობა არ პასუხობს',
                    message: "მოწყობილობამ \"{$device->name}\" ბოლო {$this->option('stale-minutes')} წუთის განმავლობაში heartbeat არ გამოაგზავნა.",
                    dedupKey: "device_fault:stale:{$device->id}:{$today}",
                    deepLink: "/devices/{$device->id}",
                );
            }
        }

        return $staleDevices->count();
    }
}
