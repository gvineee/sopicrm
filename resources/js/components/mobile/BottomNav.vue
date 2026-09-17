<script setup lang="ts">
/**
 * Mobile bottom navigation — max 5 items (spec 4: "ქვედა ნავიგაცია მაქსიმუმ
 * 5 პუნქტით"), slots reserved and documented in lib/mobileNav.ts. Fixed to
 * the viewport bottom with a safe-area inset for the home indicator, and
 * every touch target is at least 44px (spec 4: "მინიმუმ 44px შეხების
 * არე") — each item is `min-h-11` (44px) with generous horizontal padding.
 */
import { Link } from '@inertiajs/vue3';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { MOBILE_BOTTOM_NAV_SLOTS } from '@/lib/mobileNav';
import { cn } from '@/lib/utils';

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <nav
        aria-label="მთავარი ნავიგაცია"
        class="pb-safe border-sidebar-border bg-sidebar fixed inset-x-0 bottom-0 z-40 border-t md:hidden"
    >
        <ul class="grid grid-cols-5">
            <li
                v-for="slot in MOBILE_BOTTOM_NAV_SLOTS"
                :key="slot.key"
                class="min-w-0"
            >
                <component
                    :is="slot.href ? Link : 'button'"
                    :href="slot.href ?? undefined"
                    :disabled="!slot.href"
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
                            !slot.href && 'cursor-not-allowed opacity-40',
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
                </component>
            </li>
        </ul>
    </nav>
</template>
