<?php

namespace App\Domain\Shared\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Foundation-owned nav aggregator — docs/architecture.md §3.2 and §7 ("a
 * single NavigationService ... collects nav entries by loading every
 * config/modules/<module>-nav.php, merging their arrays, and filtering each
 * entry by ... the current user's resolved permissions and project
 * memberships (server-side, at render time — this is the actual enforcement
 * point, not just visual hiding, per the hard constraint)").
 *
 * A module NEVER edits this class or the shared sidebar/bottom-nav Vue
 * component to add an entry — it only ever adds/edits its own
 * config/modules/<module>-nav.php (§3.2). This is intentionally the ONLY
 * place that globs those files; a module-building agent extends the nav by
 * shipping a new config file, not by touching this service.
 *
 * Permission filtering here is a UX convenience (don't show a link the user
 * would immediately get a 403 on) — it is NOT the authorization boundary.
 * The hard constraint ("hiding a menu item is never sufficient") is
 * satisfied by every route's own Policy/Gate check, which runs regardless
 * of what this service returns; a user who guesses a hidden route's URL is
 * still stopped there, not here.
 */
class NavigationService
{
    /**
     * @return list<array{group: string, items: list<array{label: string, icon: string, href: string}>}>
     */
    public function groupsForUser(?User $user): array
    {
        /** @var array<string, list<array{label: string, icon: string, href: string}>> $itemsByGroup */
        $itemsByGroup = [];
        /** @var list<string> $groupOrder */
        $groupOrder = [];

        foreach ($this->navFiles() as $file) {
            /** @var list<array{group: string, items: list<array{label: string, icon: string, route: string, permission: string|list<string>|null}>}> $blocks */
            $blocks = require $file;

            foreach ($blocks as $block) {
                $group = $block['group'];

                foreach ($block['items'] as $entry) {
                    if (! Route::has($entry['route'])) {
                        // A module registered a nav entry for a route that
                        // doesn't exist (yet, or was renamed) — skip it
                        // rather than let route() throw and break every
                        // page's navigation for an unrelated reason.
                        continue;
                    }

                    if (! $this->isVisible($user, $entry['permission'] ?? null)) {
                        continue;
                    }

                    if (! isset($itemsByGroup[$group])) {
                        $itemsByGroup[$group] = [];
                        $groupOrder[] = $group;
                    }

                    $itemsByGroup[$group][] = [
                        'label' => $entry['label'],
                        'icon' => $entry['icon'],
                        'href' => route($entry['route']),
                    ];
                }
            }
        }

        // Keep the sidebar oriented around the user's daily work instead of
        // the alphabetical order in which module config files are loaded.
        $preferredOrder = [
            'ჩემი სამუშაო',
            'მიმოხილვა',
            'პროექტები',
            'თანამშრომლები',
            'დასწრება',
            'ანაზღაურება',
            'ინვენტარი',
            'მოწყობილობები',
            'ორგანიზაცია',
            'კლიენტები',
            'ხარისხი და უსაფრთხოება',
            'დოკუმენტები',
            'ანგარიშები',
            'ადმინისტრირება',
            'პარამეტრები',
        ];

        usort($groupOrder, static function (string $left, string $right) use ($preferredOrder): int {
            $leftPosition = array_search($left, $preferredOrder, true);
            $rightPosition = array_search($right, $preferredOrder, true);

            return ($leftPosition === false ? PHP_INT_MAX : $leftPosition)
                <=> ($rightPosition === false ? PHP_INT_MAX : $rightPosition);
        });

        return array_map(
            fn (string $group): array => ['group' => $group, 'items' => $itemsByGroup[$group]],
            $groupOrder
        );
    }

    /**
     * @return list<string>
     */
    private function navFiles(): array
    {
        $files = glob(config_path('modules/*-nav.php')) ?: [];

        sort($files);

        return $files;
    }

    /**
     * @param  string|list<string>|null  $permission
     */
    private function isVisible(?User $user, string|array|null $permission): bool
    {
        if ($user === null) {
            return false;
        }

        if ($permission === null) {
            return true;
        }

        foreach ((array) $permission as $name) {
            if ($user->can($name)) {
                return true;
            }
        }

        return false;
    }
}
