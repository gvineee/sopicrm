<?php

/**
 * Shared/Foundation module's own desktop navigation entries.
 *
 * docs/architecture.md §3.2: every module owns exactly one
 * config/modules/<module>-nav.php file returning a list of
 * {group, items[]} blocks; NavigationService (Foundation-owned,
 * app/Domain/Shared/Services/NavigationService.php) merges every module's
 * file and filters by the current user's real, server-side-resolved
 * permissions. Nobody edits NavigationService or the shared sidebar Vue
 * component to add an entry — only this kind of file.
 *
 * `group` must be one of the section-4 desktop menu groups (მიმოხილვა/
 * პროექტები/დავალებები/თანამშრომლები/დასწრება/ანაზღაურება/ხელსაწყოები/
 * საწყობი/შესყიდვები/ფინანსები/კლიენტები/ხარისხი და უსაფრთხოება/
 * დოკუმენტები/ანგარიშები/პარამეტრები). `permission` is a spatie permission
 * name (string), a list of permission names (any-of), or null for "every
 * authenticated user" (no feature-specific permission gate — the route's
 * own middleware/Policy is still the real access boundary).
 */
return [
    [
        'group' => 'მიმოხილვა',
        'items' => [
            [
                'label' => 'დეშბორდი',
                'icon' => 'layout-grid',
                'route' => 'dashboard',
                'permission' => null,
            ],
            [
                'label' => 'ჩემი პროფილი',
                'icon' => 'id-card',
                'route' => 'me.profile',
                'permission' => null,
            ],
        ],
    ],
];
