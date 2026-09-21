<?php

return [[
    'group' => 'დასწრება',
    'items' => [
        [
            'label' => 'სესიები',
            'icon' => 'clock',
            'route' => 'attendance.sessions.index',
            'permission' => 'attendance.sessions.view',
        ],
        [
            'label' => 'ანომალიები',
            'icon' => 'alert-triangle',
            'route' => 'attendance.anomalies.index',
            'permission' => 'attendance.anomalies.view',
        ],
        [
            'label' => 'ცვლის შაბლონები',
            'icon' => 'calendar-clock',
            'route' => 'attendance.shift-templates.index',
            'permission' => 'attendance.shift_templates.view',
        ],
    ],
]];
