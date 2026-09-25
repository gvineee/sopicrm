<script setup lang="ts">
/**
 * Project Manager dashboard — real Projects/Tasks data via
 * App\Http\Controllers\DashboardController. Visibility mirrors
 * App\Http\Controllers\Projects\ProjectController::index() exactly: an
 * owner sees the whole organization, everyone else only their own project
 * memberships (spec section 3). Only KPIs backed by real, computable data
 * are shown — no attendance/tool numbers are fabricated pending those
 * modules getting their own real screens (spec 4: "ყოველი მაჩვენებელი
 * რეალურ მონაცემს ... უკავშირდება").
 *
 * The design-system-only QA route (routes/modules/web-shared.php,
 * local/testing environments) renders this same component with no props —
 * every prop below defaults to an empty/zero value so that screenshot pass
 * still renders correctly without a logged-in session's real data.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Building2, CheckCircle2, Clock } from '@lucide/vue';
import { computed, ref } from 'vue';
import KpiTile from '@/components/KpiTile.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import DataTable from '@/components/data/DataTable.vue';
import TaskKanban from '@/components/tasks/TaskKanban.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import type { ColumnDef, StatusTone } from '@/types';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'მიმოხილვა', href: dashboard() }],
    },
});

type ProjectRow = {
    id: string;
    name: string;
    code: string;
    manager?: string | null;
    status: string;
    due_date?: string | null;
};

type TaskCard = {
    id: string;
    project_id: string;
    title: string;
    project_name?: string | null;
    status: string;
};

const props = withDefaults(
    defineProps<{
        kpis?: {
            active_projects: number;
            open_tasks: number;
            overdue_tasks: number;
            completed_last_30_days: number;
        };
        projects?: ProjectRow[];
        tasks?: TaskCard[];
    }>(),
    {
        kpis: () => ({ active_projects: 0, open_tasks: 0, overdue_tasks: 0, completed_last_30_days: 0 }),
        projects: () => [],
        tasks: () => [],
    },
);

const STATUS_LABEL: Record<string, string> = {
    planning: 'დაგეგმვა',
    active: 'აქტიური',
    on_hold: 'შეჩერებული',
    completed: 'დასრულებული',
    cancelled: 'გაუქმებული',
};
const STATUS_TONE: Record<string, StatusTone> = {
    planning: 'neutral',
    active: 'success',
    on_hold: 'warning',
    completed: 'info',
    cancelled: 'destructive',
};

const projectColumns: ColumnDef[] = [
    { key: 'name', header: 'პროექტი', sortable: true },
    { key: 'manager', header: 'მენეჯერი', sortable: true, hideBelow: 'md' },
    { key: 'status', header: 'სტატუსი', sortable: true, hideBelow: 'sm' },
    { key: 'due_date', header: 'ვადა', sortable: true, align: 'end', hideBelow: 'sm' },
];

</script>

<template>
    <Head title="მიმოხილვა" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div>
            <h1 class="text-foreground text-xl font-semibold">მიმოხილვა</h1>
            <p class="text-muted-foreground text-sm">თქვენთვის ხელმისაწვდომი პროექტებისა და დავალებების საერთო სურათი.</p>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <KpiTile label="აქტიური პროექტები" :value="String(kpis.active_projects)" :icon="Building2" href="/projects?status=active" />
            <KpiTile label="ღია დავალებები" :value="String(kpis.open_tasks)" :icon="Clock" href="/tasks-overview?filter=open" />
            <KpiTile
                label="ვადაგადაცილებული დავალებები"
                :value="String(kpis.overdue_tasks)"
                :icon="AlertTriangle"
                :delta-tone="kpis.overdue_tasks > 0 ? 'negative' : 'neutral'"
                href="/tasks-overview?filter=overdue"
            />
            <KpiTile
                label="დასრულებული (30 დღე)"
                :value="String(kpis.completed_last_30_days)"
                :icon="CheckCircle2"
                href="/tasks-overview?filter=completed_30d"
            />
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="text-muted-foreground text-sm font-medium">პროექტები</h2>
            <EmptyState v-if="projects.length === 0" title="პროექტი ჯერ არ არის" description="დაამატეთ პირველი პროექტი, რომ აქ სურათი გამოჩნდეს." />
            <DataTable v-else :columns="projectColumns" :rows="projects" :row-key="(row) => row.id">
                <template #cell-status="{ row }">
                    <StatusBadge :label="STATUS_LABEL[row.status] || row.status" :tone="STATUS_TONE[row.status] || 'neutral'" />
                </template>
                <template #cell-name="{ row }">
                    <Link :href="`/projects/${row.id}`" class="hover:underline">{{ row.name }}</Link>
                </template>
                <template #mobile-card="{ row }">
                    <Link :href="`/projects/${row.id}`" class="border-border bg-card block rounded-xl border p-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <p class="text-foreground min-w-0 flex-1 text-base font-medium break-words">{{ row.name }}</p>
                            <StatusBadge class="shrink-0" :label="STATUS_LABEL[row.status] || row.status" :tone="STATUS_TONE[row.status] || 'neutral'" />
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">{{ row.manager || '—' }} · {{ row.due_date || 'ვადის გარეშე' }}</p>
                    </Link>
                </template>
            </DataTable>
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="text-muted-foreground text-sm font-medium">დავალებების დაფა</h2>
                <Link href="/tasks-calendar" class="text-primary text-sm hover:underline">კალენდარში ნახვა</Link>
            </div>
            <EmptyState v-if="tasks.length === 0" title="დავალება ჯერ არ არის" />
            <TaskKanban v-else :tasks="tasks" :reload-only="['tasks', 'kpis']" />
        </section>

    </div>
</template>
