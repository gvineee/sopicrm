<?php

return [[
    'group' => 'ადმინისტრირება',
    'items' => [
        [
            'label' => 'მომხმარებლები და წვდომები',
            'icon' => 'shield-check',
            'route' => 'admin.users.index',
            'permission' => 'auth.users.view',
        ],
        [
            'label' => 'როლები',
            'icon' => 'users',
            'route' => 'admin.roles.index',
            'permission' => 'auth.users.view',
        ],
    ],
]];
