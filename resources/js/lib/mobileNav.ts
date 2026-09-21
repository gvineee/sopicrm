/**
 * Reserved mobile bottom-navigation slots (Foundation-owned).
 *
 * Spec section 4 (თანამშრომლის მობილურ ეკრანზე) names exactly these five
 * destinations for the employee's mobile bottom nav, and architecture.md
 * §3.2 requires Foundation to reserve and document the five slots so no
 * module can unilaterally add a sixth. A module implementing one of these
 * destinations points its real route at the matching slot's `href` here —
 * it does not add a new slot or edit BottomNav.vue directly.
 *
 * Every slot points at a currently registered safe landing route. Feature
 * modules can later replace these destinations with richer My Day views.
 */
import type { LucideIcon } from '@lucide/vue';
import { Bell, CalendarCheck, ClipboardList, User, Wrench } from '@lucide/vue';

export type MobileNavSlotKey =
    | 'my-day'
    | 'my-tasks'
    | 'my-tools'
    | 'notifications'
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
        label: 'დღეს',
        icon: CalendarCheck,
        href: '/dashboard',
        owner: 'Shared (Foundation placeholder) — real data owned by Tasks/Attendance',
    },
    {
        key: 'my-tasks',
        label: 'ჩემი დავალებები',
        icon: ClipboardList,
        href: '/projects',
        owner: 'Tasks',
    },
    {
        key: 'my-tools',
        label: 'ჩემი ხელსაწყოები',
        icon: Wrench,
        href: '/devices',
        owner: 'Assets',
    },
    {
        key: 'notifications',
        label: 'შეტყობინებები',
        icon: Bell,
        href: '/employees',
        owner: 'Notifications',
    },
    {
        key: 'profile',
        label: 'პროფილი',
        icon: User,
        href: '/settings/profile',
        owner: 'Auth',
    },
] as const;
