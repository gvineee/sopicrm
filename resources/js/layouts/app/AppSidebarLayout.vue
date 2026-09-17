<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import BottomNav from '@/components/mobile/BottomNav.vue';
import MobileTopBar from '@/components/mobile/MobileTopBar.vue';
import UpdateAvailableBanner from '@/components/pwa/UpdateAvailableBanner.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';
import type { ProjectOption } from '@/components/ProjectSelector.vue';

/**
 * One responsive shell for both breakpoints (docs/architecture.md §3.7:
 * this file is Foundation-owned). Below `md` (768px, matching the existing
 * Sidebar primitive's own mobile breakpoint) it swaps the sidebar rail +
 * desktop header for a slim top bar + fixed bottom nav — this is chrome
 * only; the *page content* dropped into `<slot />` is what actually differs
 * per breakpoint (e.g. a dedicated "My Day" page vs. the desktop Dashboard
 * page), which is what spec section 4 means by "own composition, not a
 * shrunk desktop copy."
 */
type Props = {
    breadcrumbs?: BreadcrumbItem[];
    mobileTitle?: string;
    projects?: ProjectOption[];
    currentProjectId?: string | null;
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    projects: () => [],
    currentProjectId: null,
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader
                :breadcrumbs="breadcrumbs"
                :projects="projects"
                :current-project-id="currentProjectId"
            />
            <MobileTopBar :title="mobileTitle" class="md:hidden" />

            <!-- Bottom padding reserves space for the fixed BottomNav below. -->
            <div class="flex-1 pb-20 md:pb-0">
                <slot />
            </div>
        </AppContent>
        <BottomNav />
        <UpdateAvailableBanner />
        <Toaster />
    </AppShell>
</template>
