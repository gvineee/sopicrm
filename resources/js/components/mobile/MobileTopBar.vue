<script setup lang="ts">
/**
 * Slim mobile top bar. The full desktop nav tree stays reachable through
 * the existing off-canvas Sidebar Sheet (reused, not rebuilt) via the
 * "მეტი" (more) button, so nothing beyond the 5 reserved bottom-nav slots
 * needs a second navigation surface built from scratch.
 */
import { Menu } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NotificationBell from '@/components/Notifications/NotificationBell.vue';
import { dashboard } from '@/routes';
import { Link } from '@inertiajs/vue3';
import { useSidebar } from '@/components/ui/sidebar';

defineProps<{ title?: string }>();

const { setOpenMobile } = useSidebar();
</script>

<template>
    <header
        class="pt-safe border-border bg-background/95 sticky top-0 z-30 flex h-14 items-center gap-2 border-b px-3 backdrop-blur"
    >
        <button
            type="button"
            aria-label="მთავარი მენიუს გახსნა"
            class="text-foreground hover:bg-accent flex size-11 items-center justify-center rounded-md"
            @click="setOpenMobile(true)"
        >
            <Menu class="size-5" />
        </button>
        <Link :href="dashboard()" class="flex shrink-0 items-center gap-2">
            <AppLogo />
        </Link>
        <h1
            v-if="title"
            class="text-foreground ml-1 min-w-0 flex-1 truncate text-sm font-medium"
        >
            {{ title }}
        </h1>
        <NotificationBell class="ml-auto shrink-0" />
    </header>
</template>
