<?php

/**
 * Daily Journal module's own desktop navigation entry
 * (docs/architecture.md §3.2). Points at the demo-organization's project
 * list is out of scope here — this is a generic entry; the real per-project
 * "დღიური ჟურნალი" link is surfaced from the project's own detail page
 * (Projects module) since the route requires a project id. This entry exists
 * so the module has a discoverable top-level destination even before a
 * specific project is chosen, per the section-4 desktop menu group
 * "პროექტები".
 *
 * Real permission enforcement happens in DailyReportPolicy/the controller —
 * this file only decides whether the nav item is worth showing at all.
 */
return [
    [
        'group' => 'პროექტები',
        'items' => [
            [
                'label' => 'დღიური ჟურნალი',
                'icon' => 'calendar-check',
                'route' => 'dashboard',
                'permission' => 'dailyjournal.reports.view',
            ],
        ],
    ],
];
