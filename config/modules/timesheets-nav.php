<?php

return [[
    'group' => 'დასწრება',
    'items' => [
        [
            'label' => 'ტაბელები',
            'icon' => 'clipboard-list',
            'route' => 'timesheets.index',
            'permission' => 'timesheets.timesheets.view',
        ],
        [
            'label' => 'შესწორებები',
            'icon' => 'file-edit',
            'route' => 'attendance-adjustments.index',
            'permission' => 'timesheets.adjustments.view',
        ],
    ],
]];
