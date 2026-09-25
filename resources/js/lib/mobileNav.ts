/**
 * Reserved mobile bottom-navigation slots (Foundation-owned).
 *
 * Mobile destinations are deliberately short and familiar. My Day and
 * Profile are always available; Projects, Timesheet and Inventory are
 * populated from the user's server-filtered navigation in BottomNav.vue.
 * The full set of permitted modules remains available in the slide-out menu.
 */
import type { LucideIcon } from '@lucide/vue';
import { CalendarCheck, ClipboardList, Package, User, WalletCards } from '@lucide/vue';

export type MobileNavSlotKey =
    | 'my-day'
    | 'projects'
    | 'timesheets'
    | 'assets'
    | 'profile';

export type MobileNavSlot = {
    key: MobileNavSlotKey;
    label: string;
    icon: LucideIcon;
    href: string | null;
    /** Owning module, for traceability — see architecture.md §3.2. */
    owner: string;
};

export const MOBILE_BOTTOM_NAV_SLOTS: readonly MobileNavSlot[] = [
    {
        key: 'my-day',
        label: 'ჩემი დღე',
        icon: CalendarCheck,
        href: '/my-day',
        owner: 'Shared / Tasks',
    },
    {
        key: 'projects',
        label: 'პროექტები',
        icon: ClipboardList,
        href: null,
        owner: 'Projects',
    },
    {
        key: 'timesheets',
        label: 'ტაბელი',
        icon: WalletCards,
        href: null,
        owner: 'Timesheets',
    },
    {
        key: 'assets',
        label: 'ინვენტარი',
        icon: Package,
        href: null,
        owner: 'Assets',
    },
    {
        // Audit A25: this slot goes to /settings/profile — the ACCOUNT
        // settings screen (name, email, password) — while the menu's
        // „ჩემი პროფილი" goes to /me/profile, the WORK profile (employee
        // record, internal code, access events). Two different pages behind
        // two names that read the same is how a person concludes one of them
        // is broken, so this one is named after what it actually opens.
        key: 'profile',
        label: 'ანგარიში',
        icon: User,
        href: '/settings/profile',
        owner: 'Auth',
    },
] as const;
