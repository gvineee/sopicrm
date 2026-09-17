<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { ProjectOption } from '@/components/ProjectSelector.vue';

const {
    breadcrumbs = [],
    mobileTitle,
    projects = [],
    currentProjectId = null,
} = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
    /** Page title shown in the mobile top bar (spec 4 mobile shell) — set per-page via `defineOptions({ layout: { mobileTitle: '...' } })`. */
    mobileTitle?: string;
    projects?: ProjectOption[];
    currentProjectId?: string | null;
}>();
</script>

<template>
    <AppLayout
        :breadcrumbs="breadcrumbs"
        :mobile-title="mobileTitle"
        :projects="projects"
        :current-project-id="currentProjectId"
    >
        <slot />
    </AppLayout>
</template>
