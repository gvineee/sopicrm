<?php

/**
 * Platform administration's nav entry. `manage-organizations` is a Gate, not
 * a spatie permission — only `users.is_platform_admin` satisfies it
 * (App\Providers\Auth\AuthModuleServiceProvider), so no tenant role, owner
 * included, ever sees this link. The routes enforce the same Gate themselves.
 */
return [[
    'group' => 'ადმინისტრირება',
    'items' => [
        [
            'label' => 'ორგანიზაციები',
            'icon' => 'building-2',
            'route' => 'platform.organizations.index',
            'permission' => 'manage-organizations',
        ],
    ],
]];
