<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import InstallControls from '@/components/pwa/InstallControls.vue';
import ProjectSelector from '@/components/ProjectSelector.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';
import type { ProjectOption } from '@/components/ProjectSelector.vue';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
        /**
         * Populated once the Projects module exposes a shared Inertia prop
         * (e.g. `usePage().props.projects`) — see ProjectSelector.vue's own
         * docblock and docs/decisions.md DEC-048. Empty by default so the
         * shell renders correctly before that wiring exists.
         */
        projects?: ProjectOption[];
        currentProjectId?: string | null;
    }>(),
    {
        breadcrumbs: () => [],
        projects: () => [],
        currentProjectId: null,
    },
);
</script>

<template>
    <header
        class="border-sidebar-border/70 hidden h-16 shrink-0 items-center gap-3 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:flex md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <ProjectSelector
                v-if="projects.length > 0"
                :projects="projects"
                :model-value="currentProjectId"
            />
            <GlobalSearch />
            <InstallControls />
        </div>
    </header>
</template>
