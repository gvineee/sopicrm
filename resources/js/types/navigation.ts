import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};

/**
 * Shape sent by App\Domain\Shared\Services\NavigationService (the
 * `navGroups` shared Inertia prop) — already permission-filtered
 * server-side. `icon` is a string key (config/modules/*-nav.php can't hand
 * over a Vue component) resolved to a real icon via
 * `@/lib/navIcons`'s `resolveNavIcon`.
 */
export type ServerNavItem = {
    label: string;
    icon: string;
    href: string;
};

export type ServerNavGroup = {
    group: string;
    items: ServerNavItem[];
};
