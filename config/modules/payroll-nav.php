<?php

return [[
    'group' => 'ანაზღაურება',
    'items' => [
        [
            'label' => 'ანაზღაურების პერიოდები',
            'icon' => 'calendar-range',
            'route' => 'payroll.pay-periods.index',
            'permission' => 'payroll.pay-periods.view',
        ],
        [
            'label' => 'ანგარიშსწორებები',
            'icon' => 'banknote',
            'route' => 'payroll.pay-runs.index',
            'permission' => 'payroll.pay-runs.view',
        ],
        [
            'label' => 'ავანსები',
            'icon' => 'hand-coins',
            'route' => 'payroll.advances.index',
            'permission' => 'payroll.advances.view',
        ],
    ],
]];
