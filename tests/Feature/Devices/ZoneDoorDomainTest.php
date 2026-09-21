<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\Door;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Models\Zone;
use App\Domain\Shared\Services\CurrentOrganization;

pest()->group('devices');

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    $this->site = Site::factory()->create(['organization_id' => $this->organization->id]);
});

test('a zone resolves devices through its doors, not just directly assigned devices', function () {
    $zone = Zone::factory()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id]);

    $frontDevice = Device::factory()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id]);
    $backDevice = Device::factory()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id]);
    $unzonedDevice = Device::factory()->create(['organization_id' => $this->organization->id, 'site_id' => $this->site->id]);

    Door::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
        'zone_id' => $zone->id,
        'device_id' => $frontDevice->id,
        'name' => 'Front door',
    ]);
    Door::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
        'zone_id' => $zone->id,
        'device_id' => $backDevice->id,
        'name' => 'Back door',
    ]);
    // A door with no zone must not leak into this zone's device list.
    Door::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
        'zone_id' => null,
        'device_id' => $unzonedDevice->id,
        'name' => 'Unzoned door',
    ]);

    $deviceIds = $zone->devices()->pluck('devices.id')->sort()->values()->all();

    expect($deviceIds)->toEqual(collect([$frontDevice->id, $backDevice->id])->sort()->values()->all())
        ->and($deviceIds)->not->toContain($unzonedDevice->id)
        ->and($zone->doors()->count())->toBe(2);
});
