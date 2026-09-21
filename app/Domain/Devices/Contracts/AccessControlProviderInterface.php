<?php

namespace App\Domain\Devices\Contracts;

/**
 * Vendor-neutral access-control boundary. DeviceAdapterInterface is retained
 * for backwards compatibility with existing jobs; new domain code may depend
 * on this name without coupling itself to Suprema.
 */
interface AccessControlProviderInterface extends DeviceAdapterInterface {}
