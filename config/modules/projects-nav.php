<?php

/**
 * Projects/Tasks module's own desktop navigation entry (docs/architecture.md
 * Module Contribution Convention). Real permission enforcement happens in
 * ProjectPolicy/TaskPolicy and the controllers — this only decides whether
 * the entry is worth showing at all.
 */
return [
    [
        'group' => 'პროექტები',
        'items' => [
            [
                'label' => 'პროექტები',
                'icon' => 'layout-grid',
                'route' => 'projects.index',
                'permission' => ['projects.view', 'projects.viewAny'],
            ],
        ],
    ],
];
