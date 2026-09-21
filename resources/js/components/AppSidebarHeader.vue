<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import type { SearchResult } from '@/components/GlobalSearch.vue';
import NotificationBell from '@/components/Notifications/NotificationBell.vue';
import InstallControls from '@/components/pwa/InstallControls.vue';
import ProjectSelector from '@/components/ProjectSelector.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { api } from '@/lib/api';
import type { BreadcrumbItem } from '@/types';
import type { ProjectOption } from '@/components/ProjectSelector.vue';
import { ref } from 'vue';

/**
 * NOTIFY-01: GlobalSearch.vue is a UI-only stub by design (see its own
 * docblock) — this is the real consumer wired to the GlobalSearchController
 * JSON endpoint, owned here rather than threaded through every page's
 * Inertia props (search must be reactive without a full page reload, and
 * every page in this app renders through this one shared header).
 */
const searchResults = ref<SearchResult[]>([]);
const searchLoading = ref(false);
let searchRequestId = 0;

async function handleSearchQuery(query: string) {
    if (query.trim().length < 2) {
        searchResults.value = [];
        return;
    }

    const requestId = ++searchRequestId;
    searchLoading.value = true;

    try {
        const data = await api.get<{ results: SearchResult[] }>(`/search?q=${encodeURIComponent(query)}`);
        if (requestId === searchRequestId) {
            searchResults.value = data.results;
        }
    } catch {
        if (requestId === searchRequestId) {
            searchResults.value = [];
        }
    } finally {
        if (requestId === searchRequestId) {
            searchLoading.value = false;
        }
    }
}

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
            <GlobalSearch
                :results="searchResults"
                :loading="searchLoading"
                @query="handleSearchQuery"
            />
            <NotificationBell />
            <InstallControls />
        </div>
    </header>
</template>
