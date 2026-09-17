<?php

/**
 * Tasks module's own config (docs/architecture.md §2:
 * `config/modules/<module>.php`, owned exclusively by this module).
 *
 * Values below are spec section 21's own explicit "starting defaults"
 * ("კონფიგურირებადი, spec 21 საწყისი მნიშვნელობებით: ფოტო 20 MB, PDF 50 MB,
 * მაქს. 20 ფაილი submission-ზე") — not fabricated, and overridable
 * per-deployment via env without a code change.
 */
return [
    'attachments' => [
        'max_photo_mb' => (int) env('TASKS_MAX_PHOTO_MB', 20),
        'max_pdf_mb' => (int) env('TASKS_MAX_PDF_MB', 50),
        'max_files_per_task' => (int) env('TASKS_MAX_FILES_PER_TASK', 20),
    ],
];
