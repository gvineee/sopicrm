<?php

/**
 * Devices module's own desktop navigation entries (docs/architecture.md
 * Module Contribution Convention — nav is registered here, never by editing
 * a shared sidebar component). Real permission enforcement happens in
 * DevicePolicy/CredentialPolicy and the controllers; these `permission`
 * strings only decide whether the entry is worth showing at all.
 */
return [
    [
        'group' => 'მოწყობილობები',
        'items' => [
            [
                'label' => 'მოწყობილობები',
                'icon' => 'cpu',
                'route' => 'devices.index',
                'permission' => 'devices.view',
            ],
            [
                'label' => 'ბარათები',
                'icon' => 'id-card',
                'route' => 'credentials.index',
                'permission' => 'devices.credentials.view',
            ],
            [
                'label' => 'უცნობი ბარათები',
                'icon' => 'shield-question',
                'route' => 'devices.external-mappings.index',
                'permission' => 'devices.external_mappings.view',
            ],
        ],
    ],
];
