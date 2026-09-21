<?php

namespace App\Domain\Auth\Support;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * ADMIN-02 "functionality groups": every seeded permission already follows
 * the `<module>.<resource>.<action>` naming convention (see any
 * database/seeders/modules/*PermissionsSeeder.php docblock) — this groups
 * permissions by that existing module prefix instead of inventing a second,
 * separately-maintained grouping concept. The label map below must stay in
 * sync with every prefix actually seeded; an unmapped prefix falls back to
 * itself rather than being silently dropped, so a new module's permissions
 * are still visible (just unlabeled in Georgian) until this map is updated.
 */
class PermissionGroups
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'attendance' => 'დასწრება',
        'audit' => 'აუდიტი',
        'auth' => 'წვდომა/ადმინისტრირება',
        'companies' => 'კომპანიები',
        'contractors' => 'კონტრაქტორები',
        'dailyjournal' => 'დღიური ჟურნალი',
        'devices' => 'მოწყობილობები',
        'employees' => 'თანამშრომლები',
        'finance' => 'ფინანსები',
        'payroll' => 'ანაზღაურება',
        'projects' => 'პროექტები',
        'tasks' => 'დავალებები',
        'timesheets' => 'ტაბელები',
    ];

    /**
     * @return Collection<string, array{key: string, label: string, permissions: list<string>}>
     */
    public static function grouped(): Collection
    {
        return Permission::query()
            ->orderBy('name')
            ->pluck('name')
            ->groupBy(fn (string $name): string => self::prefixOf($name))
            ->map(fn (Collection $names, string $prefix): array => [
                'key' => $prefix,
                'label' => self::LABELS[$prefix] ?? $prefix,
                'permissions' => array_values($names->map(fn (mixed $name): string => (string) $name)->all()),
            ])
            ->sortBy('label')
            ->values()
            ->keyBy('key');
    }

    public static function labelFor(string $permissionName): string
    {
        return self::LABELS[self::prefixOf($permissionName)] ?? self::prefixOf($permissionName);
    }

    private static function prefixOf(string $permissionName): string
    {
        return explode('.', $permissionName, 2)[0];
    }
}
