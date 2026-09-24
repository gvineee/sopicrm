<script setup lang="ts">
/**
 * Audit A15 / acceptance NAV-02: the filtered task list a dashboard KPI
 * card opens. Backed by App\Http\Controllers\DashboardController::tasks(),
 * which builds this list from the same visibility + filter helpers the KPI
 * counts themselves use — so the number on the card and the total here are
 * one query, not two that can drift.
 *
 * The active filter lives in the URL (`?filter=…`), so Back/Reload restores
 * exactly what the operator was looking at (spec 02 §3, acceptance NAV-03).
 */
import { Head, Link } from '@inertiajs/vue3';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import type { StatusTone } from '@/types';

type OverviewTask = {
    id: string;
    project_id: string;
    project_name?: string | null;
    title: string;
    status: string;
    priority: string;
    due_at?: string | null;
    owner_name?: string | null;
};

const props = defineProps<{
    filter: string;
    title: string;
    tasks: OverviewTask[];
    pagination: { page: number; perPage: number; total: number };
}>();

defineOptions({ layout: { mobileTitle: 'დავალებები' } });

// Spec 02 §3 glossary: "შემოწმებაზე" rather than the ambiguous
// "გაგზავნილია" for work waiting on a reviewer.
const STATUS_LABEL: Record<string, string> = {
    draft: 'მონახაზი',
    assigned: 'მინიჭებული',
    in_progress: 'მიმდინარეობს',
    blocked: 'დაბლოკილი',
    submitted: 'შემოწმებაზე',
    completed: 'დასრულებული',
    cancelled: 'გაუქმებული',
};

const STATUS_TONE: Record<string, StatusTone> = {
    draft: 'neutral',
    assigned: 'info',
    in_progress: 'info',
    blocked: 'warning',
    submitted: 'warning',
    completed: 'success',
    cancelled: 'destructive',
};

const PRIORITY_LABEL: Record<string, string> = {
    urgent: 'გადაუდებელი',
    high: 'მაღალი',
    normal: 'ჩვეულებრივი',
    low: 'დაბალი',
};

const TABS: Array<{ key: string; label: string }> = [
    { key: 'open', label: 'ღია' },
    { key: 'overdue', label: 'ვადაგადაცილებული' },
    { key: 'completed_30d', label: 'დასრულებული (30 დღე)' },
];

function formatDate(value?: string | null): string {
    if (!value) return '—';

    // Localized, no ISO string on screen (spec 02 §3).
    return new Date(`${value}T00:00:00`).toLocaleDateString('ka-GE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

void props;
</script>

<template>
    <Head :title="title" />
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/dashboard" class="text-muted-foreground text-sm hover:underline">← მიმოხილვა</Link>
            <h1 class="mt-2 text-2xl font-semibold">{{ title }}</h1>
            <p class="text-muted-foreground text-sm">სულ: {{ pagination.total }}</p>
        </div>

        <nav class="flex flex-wrap gap-2" aria-label="დავალებების ფილტრი">
            <Link
                v-for="tab in TABS"
                :key="tab.key"
                :href="`/tasks-overview?filter=${tab.key}`"
                :aria-current="tab.key === filter ? 'page' : undefined"
                :class="[
                    'rounded-full border px-3 py-1 text-sm',
                    tab.key === filter
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border text-muted-foreground hover:bg-accent',
                ]"
            >
                {{ tab.label }}
            </Link>
        </nav>

        <EmptyState
            v-if="tasks.length === 0"
            title="ამ ფილტრით დავალება ვერ მოიძებნა"
            description="სცადეთ სხვა ფილტრი — ან ამ კატეგორიაში ჯერ არაფერია."
        />

        <div v-else class="border-border bg-card overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="text-muted-foreground border-border border-b text-left">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">დავალება</th>
                        <th scope="col" class="px-4 py-2 font-medium">პროექტი</th>
                        <th scope="col" class="px-4 py-2 font-medium">სტატუსი</th>
                        <th scope="col" class="px-4 py-2 font-medium">პრიორიტეტი</th>
                        <th scope="col" class="px-4 py-2 font-medium">პასუხისმგებელი</th>
                        <th scope="col" class="px-4 py-2 font-medium">ვადა</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="task in tasks" :key="task.id" class="border-border border-b last:border-b-0">
                        <td class="px-4 py-2">
                            <Link :href="`/projects/${task.project_id}/tasks/${task.id}`" class="hover:underline">
                                {{ task.title }}
                            </Link>
                        </td>
                        <td class="text-muted-foreground px-4 py-2">{{ task.project_name || '—' }}</td>
                        <td class="px-4 py-2">
                            <StatusBadge
                                :label="STATUS_LABEL[task.status] || task.status"
                                :tone="STATUS_TONE[task.status] || 'neutral'"
                            />
                        </td>
                        <td class="text-muted-foreground px-4 py-2">{{ PRIORITY_LABEL[task.priority] || task.priority }}</td>
                        <td class="text-muted-foreground px-4 py-2">{{ task.owner_name || '—' }}</td>
                        <td class="text-muted-foreground px-4 py-2">{{ formatDate(task.due_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
