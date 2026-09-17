<?php

return [
    [
        'group' => 'თანამშრომლები',
        'items' => [
            [
                'label' => 'თანამშრომლები',
                'icon' => 'users',
                'route' => 'employees.index',
                'permission' => 'employees.employees.view',
            ],
            [
                'label' => 'ბრიგადები',
                'icon' => 'users-round',
                'route' => 'teams.index',
                'permission' => 'employees.teams.manage',
            ],
        ],
    ],
];
