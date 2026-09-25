<script setup lang="ts">
/**
 * Mobile bottom navigation — max 5 items (spec 4: "ქვედა ნავიგაცია მაქსიმუმ
 * 5 პუნქტით"), slots reserved and documented in lib/mobileNav.ts. Fixed to
 * the viewport bottom with a safe-area inset for the home indicator, and
 * every touch target is at least 44px (spec 4: "მინიმუმ 44px შეხების
 * არე") — each item is `min-h-11` (44px) with generous horizontal padding.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { MOBILE_BOTTOM_NAV_SLOTS } from '@/lib/mobileNav';
import { resolveNavIcon } from '@/lib/navIcons';
import { cn } from '@/lib/utils';

const { isCurrentUrl } = useCurrentUrl();
const page = usePage();

const navItems = computed(() => {
    const authorizedItems = page.props.navGroups.flatMap((group) => group.items);
    const destinations: Record<string, string> = {
        projects: '/projects',
        timesheets: '/timesheets',
        assets: '/assets',
    };

    return MOBILE_BOTTOM_NAV_SLOTS.flatMap((slot) => {
        if (slot.key === 'my-day') {
            return [{ ...slot, href: '/my-day', icon: slot.icon }];
        }

        if (slot.key === 'profile') {
            return [{ ...slot, href: '/settings/profile', icon: slot.icon }];
        }

        const authorizedItem = authorizedItems.find((item) => {
            const pathname = new URL(item.href, 'http://crm.local').pathname.replace(/\/$/, '');
            return pathname === destinations[slot.key];
        });

        return authorizedItem
            ? [{ ...slot, href: authorizedItem.href, icon: resolveNavIcon(authorizedItem.icon) }]
            : [];
    });
});
</script>

<template>
    <nav
        aria-label="მთავარი ნავიგაცია"
        class="pb-safe border-sidebar-border bg-sidebar fixed inset-x-0 bottom-0 z-40 border-t md:hidden"
    >
        <ul class="grid" :style="{ gridTemplateColumns: `repeat(${navItems.length}, minmax(0, 1fr))` }">
            <li
                v-for="slot in navItems"
                :key="slot.key"
                class="min-w-0"
            >
                <Link
                    :href="slot.href"
                    :aria-current="
                        slot.href && isCurrentUrl(slot.href)
                            ? 'page'
                            : undefined
                    "
                    :class="
                        cn(
                            'flex min-h-11 w-full min-w-0 flex-col items-center justify-center gap-0.5 overflow-hidden px-1 py-1.5 text-[11px] leading-none',
                            slot.href && isCurrentUrl(slot.href)
                                ? 'text-sidebar-primary'
                                : 'text-sidebar-foreground/70',
                        )
                    "
                >
                    <component
                        :is="slot.icon"
                        class="size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <!--
                        `block w-full`: without an explicit block width, this
                        span (a flex item under `items-center`) sizes to its
                        own text and visually bleeds past the nav item's
                        boundary instead of truncating — Georgian compound
                        words are long enough that this genuinely pushed the
                        whole page's horizontal extent past the viewport
                        (see docs/decisions.md DEC-050, the concrete bug this
                        fixed, found via pixel-level screenshot inspection).
                    -->
                    <span class="block w-full min-w-0 truncate text-center">{{
                        slot.label
                    }}</span>
                </Link>
            </li>
        </ul>
    </nav>
</template>
