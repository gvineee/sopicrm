<?php

return [[
    'group' => 'ინვენტარი',
    'items' => [
        [
            'label' => 'აქტივები',
            'icon' => 'package',
            'route' => 'assets.index',
            'permission' => 'assets.assets.view',
        ],
        [
            'label' => 'ინვენტარიზაცია',
            'icon' => 'clipboard-list',
            'route' => 'assets.stocktakes.index',
            'permission' => ['assets.stocktakes.perform', 'assets.stocktakes.approve'],
        ],
    ],
]];
