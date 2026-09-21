/**
 * Icon key -> Lucide component lookup for server-driven nav entries
 * (`config/modules/<module>-nav.php`'s `icon` string, delivered via the
 * `navGroups` shared Inertia prop). A module adds its own icon key here
 * when it registers a nav entry that needs one not already listed — this
 * file is Foundation-owned infra (docs/architecture.md §3.2's "shared
 * component" rule applies the same way to this lookup table as to the
 * sidebar Vue component itself), so a module proposes a new key by adding
 * it here in the same change that adds its `-nav.php` entry, rather than
 * inventing a per-module icon-resolution mechanism.
 */
import type { LucideIcon } from '@lucide/vue';
import {
    AlertTriangle,
    Banknote,
    Building2,
    CalendarClock,
    CalendarRange,
    ClipboardList,
    Clock,
    Cpu,
    FileEdit,
    HandCoins,
    Handshake,
    HelpCircle,
    IdCard,
    LayoutGrid,
    MapPin,
    Package,
    ShieldCheck,
    ShieldQuestion,
    Users,
    UsersRound,
} from '@lucide/vue';

const NAV_ICON_MAP: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    users: Users,
    'users-round': UsersRound,
    cpu: Cpu,
    'id-card': IdCard,
    'building-2': Building2,
    handshake: Handshake,
    clock: Clock,
    'alert-triangle': AlertTriangle,
    'calendar-clock': CalendarClock,
    'clipboard-list': ClipboardList,
    'file-edit': FileEdit,
    'calendar-range': CalendarRange,
    banknote: Banknote,
    'hand-coins': HandCoins,
    'shield-question': ShieldQuestion,
    'shield-check': ShieldCheck,
    package: Package,
    'map-pin': MapPin,
};

/** Falls back to a generic icon rather than throwing on an unknown key. */
export function resolveNavIcon(key: string): LucideIcon {
    return NAV_ICON_MAP[key] ?? HelpCircle;
}
