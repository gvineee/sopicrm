<?php

/**
 * BOOTSTRAP LOOP ONLY — see docs/architecture.md §3.1 (Module Contribution Convention).
 *
 * No module-building agent may ever edit this file after Foundation creates it.
 * Only the Integration agent may touch it again, and only to fix the bootstrap
 * mechanism itself (never to add a route directly).
 *
 * Every module that exposes /api/v1 resources owns exactly one
 * routes/modules/api-<module>.php file, wrapped in that module's own
 * middleware group. Sort order is filename-alphabetical; a module needing
 * guaranteed ordering relative to another expresses that as an explicit
 * route-name check inside its own file, not by renaming files to force load
 * order.
 */
foreach (glob(__DIR__.'/modules/api-*.php') ?: [] as $file) {
    require $file;
}
