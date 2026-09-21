<?php

/**
 * Daily Journal module's own desktop navigation entry
 * (docs/architecture.md §3.2). Points at `daily-journal.projects`
 * (DailyReportController::projects(), JOURNAL-01) — a parameter-less
 * landing page listing the projects the current user has journal access to,
 * each linking into that project's own `daily-journal.index`. This entry
 * exists so the module has a discoverable top-level destination even before
 * a specific project is chosen, per the section-4 desktop menu group
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
                'route' => 'daily-journal.projects',
                'permission' => 'dailyjournal.reports.view',
            ],
        ],
    ],
];
