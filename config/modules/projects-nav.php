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
            [
                // Audit A24: there was no route into client management from
                // anywhere in the product.
                'label' => 'კლიენტები',
                'icon' => 'contact',
                'route' => 'clients.index',
                'permission' => ['projects.view', 'projects.clients.manage'],
            ],
            [
                'label' => 'დავალებების კალენდარი',
                'icon' => 'calendar-check',
                'route' => 'tasks.calendar',
                'permission' => null,
            ],
        ],
    ],
];
