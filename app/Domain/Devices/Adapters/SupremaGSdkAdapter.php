<?php

namespace App\Domain\Devices\Adapters;

use App\Domain\Devices\Contracts\AccessControlProviderInterface;
use App\Domain\Devices\Contracts\DeviceAdapterInterface;
use App\Domain\Devices\DataTransferObjects\DeviceCommandResult;
use App\Domain\Devices\Exceptions\RealHardwareNotConfiguredException;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use Illuminate\Support\Collection;

/**
 * Real Suprema adapter — spec section 6 / docs/architecture.md §6.
 *
 * DOCUMENTED INTEGRATION PLAN (not yet executed against real hardware):
 *
 * 1. Topology: `ODA API/worker ↔ ადგილობრივი integration service/Gateway ↔
 *    მოწყობილობები დაცულ LAN/VPN-ში` — this class runs INSIDE the Laravel
 *    process only as a thin client; it would call out to
 *    `services/device-connector` (a separate OS process on the protected
 *    LAN/VPN, see that service's own README) over the versioned,
 *    authenticated contract described in docs/architecture.md §6, which in
 *    turn speaks the Suprema G-SDK / Device Gateway protocol to the actual
 *    XPass2 units. The browser never talks to a device directly, and no
 *    device port is ever exposed to the public internet.
 * 2. G-SDK surfaces this would use, per spec section 24's confirmed sources:
 *    - Device API (`https://supremainc.github.io/g-sdk/api/device/`) for
 *      `readCapabilities()` — XPASS2 device type identification and live
 *      capability/limit queries, never a hardcoded number.
 *    - User API (`https://supremainc.github.io/g-sdk/api/user/`) for
 *      `applyCommand()`'s add_user/update_user/revoke_credential/
 *      sync_access_group/sync_schedule command types.
 *    - Event API (`https://supremainc.github.io/g-sdk/api/event/`) for
 *      `pullEvents()`.
 * 3. Secrets: the Gateway connection's credentials/certificates are
 *    server-side only (`services/device-connector`'s own config, never
 *    committed, never sent to the browser); the connection itself uses
 *    whatever encrypted transport the Gateway/G-SDK supports.
 * 4. Card enrollment mode (EM passive read vs. MIFARE sector
 *    read/write) and the exact byte order a given reader emits are
 *    PILOT DECISIONS confirmed against real hardware, not assumed here —
 *    spec section 6: "EM/MIFARE-ის ფაქტობრივი enrollment რეჟიმი პილოტში
 *    განისაზღვროს."
 * 5. Firmware/G-SDK version selection itself is confirmed at pilot time
 *    against the actual deployed XPass2 firmware (spec section 6: "არჩევანი
 *    დაადასტურე firmware-ისა და გარემოს შემოწმებით").
 *
 * Until that pilot happens, every method below throws rather than
 * fabricating a result — the hard constraint is explicit that simulator
 * runs must never be represented as real-hardware validation, and a
 * same-shaped "success" return from here would do exactly that silently.
 */
final class SupremaGSdkAdapter implements AccessControlProviderInterface, DeviceAdapterInterface
{
    public function label(): string
    {
        return 'Suprema G-SDK (რეალური მოწყობილობა — დაუდასტურებელი)';
    }

    public function isSimulator(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function readCapabilities(Device $device): array
    {
        throw $this->notConfigured();
    }

    public function applyCommand(Device $device, DeviceSyncCommand $command): DeviceCommandResult
    {
        throw $this->notConfigured();
    }

    public function pollHeartbeat(Device $device): string
    {
        throw $this->notConfigured();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pullEvents(Device $device, int $sinceStreamEpoch, int $sinceNativeEventId, int $limit = 100): Collection
    {
        throw $this->notConfigured();
    }

    private function notConfigured(): RealHardwareNotConfiguredException
    {
        return new RealHardwareNotConfiguredException(
            'Suprema G-SDK Device Gateway ინტეგრაცია დოკუმენტირებულია (იხ. ამ კლასის docblock და '.
            'docs/decisions.md), მაგრამ ამ გარემოში რეალურ მოწყობილობასთან დაკავშირებული და დადასტურებული '.
            'არ არის. სისტემა განზრახ არ ახორციელებს ოპერაციას რეალურ hardware-ზე ვალიდაციის გარეშე — '.
            'გამოიყენეთ სიმულატორი (DEVICES_ADAPTER=simulator) სანამ პილოტი რეალურ XPass2 მოწყობილობაზე არ ჩატარდება.'
        );
    }
}
