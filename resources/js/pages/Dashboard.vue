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
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import DataTable from '@/components/data/DataTable.vue';
import KanbanBoard from '@/components/data/KanbanBoard.vue';
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

const TASK_KANBAN_COLUMN: Record<string, string> = {
    draft: 'todo',
    assigned: 'todo',
    in_progress: 'in_progress',
    blocked: 'in_progress',
    submitted: 'done',
    completed: 'done',
};

const kanbanColumns = [
    { key: 'todo', title: 'დასაწყები' },
    { key: 'in_progress', title: 'მიმდინარე' },
    { key: 'done', title: 'დასრულებული' },
];

// Reactive (not a one-time computation): after a real transition, the board
// reloads `tasks` from the server (see reloadBoard() below) and this must
// re-derive from the fresh prop, not keep rendering the page's original
// snapshot forever.
const tasksByColumn = computed(() => {
    const byColumn: Record<string, TaskCard[]> = { todo: [], in_progress: [], done: [] };
    for (const task of props.tasks) {
        const column = TASK_KANBAN_COLUMN[task.status] ?? 'todo';
        byColumn[column].push(task);
    }

    return byColumn;
});

function openTask(card: TaskCard) {
    router.visit(`/projects/${card.project_id}/tasks/${card.id}`);
}

function reloadBoard() {
    router.reload({ only: ['tasks', 'kpis'] });
}

/**
 * Maps a Kanban drag-drop move to the ONE real Task Action it actually
 * corresponds to. KanbanBoard is purely presentational (it renders directly
 * from the `tasksByColumn` prop above, never mutates a card's column
 * itself), so an invalid/rejected move needs no "revert" — the board never
 * moved anything until the server confirmed it and `reloadBoard()` ran.
 *
 * Real column-pair mapping (the only two moves with a single corresponding
 * Action — see App\Http\Controllers\Tasks\TaskController /
 * App\Domain\Tasks\Actions\{StartTask,SubmitTaskForAcceptance}):
 *   todo (status=assigned)      -> in_progress : StartTask (no extra input)
 *   in_progress (status=in_progress, NOT blocked) -> done : SubmitTaskForAcceptance (optional comment)
 * Every other pair (including anything involving a `blocked` or `draft`
 * card, or a backwards move) has no single corresponding Action and is
 * rejected with an explicit message — never silently ignored, never
 * fabricated.
 */
const moveError = ref<string | null>(null);
const submitDialog = ref<{ open: boolean; card: TaskCard | null; comment: string; processing: boolean }>({
    open: false,
    card: null,
    comment: '',
    processing: false,
});

function handleMove({ card, toColumn }: { card: TaskCard; toColumn: string }) {
    moveError.value = null;
    const fromColumn = TASK_KANBAN_COLUMN[card.status] ?? 'todo';

    if (fromColumn === toColumn) {
        return;
    }

    if (fromColumn === 'todo' && toColumn === 'in_progress' && card.status === 'assigned') {
        router.post(
            `/projects/${card.project_id}/tasks/${card.id}/start`,
            {},
            {
                preserveScroll: true,
                onSuccess: reloadBoard,
                onError: (errors) => {
                    moveError.value = Object.values(errors)[0] ?? 'დავალების დაწყება ვერ მოხერხდა.';
                },
            },
        );

        return;
    }

    if (fromColumn === 'in_progress' && toColumn === 'done' && card.status === 'in_progress') {
        submitDialog.value = { open: true, card, comment: '', processing: false };

        return;
    }

    moveError.value = 'ეს გადასვლა ხელით შესაძლებელი არაა — გახსენით დავალება საჭირო მოქმედების შესასრულებლად.';
}

function confirmSubmit() {
    const card = submitDialog.value.card;
    if (!card) {
        return;
    }

    submitDialog.value.processing = true;
    router.post(
        `/projects/${card.project_id}/tasks/${card.id}/submit`,
        { comment: submitDialog.value.comment || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                submitDialog.value = { open: false, card: null, comment: '', processing: false };
                reloadBoard();
            },
            onError: (errors) => {
                submitDialog.value.processing = false;
                moveError.value = Object.values(errors)[0] ?? 'დავალების გაგზავნა ვერ მოხერხდა.';
            },
        },
    );
}

function cancelSubmitDialog() {
    submitDialog.value = { open: false, card: null, comment: '', processing: false };
}
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
            <KpiTile label="ღია დავალებები" :value="String(kpis.open_tasks)" :icon="Clock" href="/projects" />
            <KpiTile
                label="ვადაგადაცილებული დავალებები"
                :value="String(kpis.overdue_tasks)"
                :icon="AlertTriangle"
                :delta-tone="kpis.overdue_tasks > 0 ? 'negative' : 'neutral'"
                href="/projects"
            />
            <KpiTile label="დასრულებული (30 დღე)" :value="String(kpis.completed_last_30_days)" :icon="CheckCircle2" href="/projects" />
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
            <p v-if="moveError" class="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border px-3 py-2 text-sm">
                {{ moveError }}
            </p>
            <EmptyState v-if="tasks.length === 0" title="დავალება ჯერ არ არის" />
            <KanbanBoard v-else :columns="kanbanColumns" :cards-by-column="tasksByColumn" @move="handleMove">
                <template #card="{ card }">
                    <p class="text-foreground text-sm font-medium">{{ card.title }}</p>
                    <p class="text-muted-foreground text-xs">{{ card.project_name }}</p>
                </template>
                <template #card-actions="{ card }">
                    <button
                        type="button"
                        class="border-border text-muted-foreground hover:bg-accent rounded-full border px-2 py-0.5 text-[11px]"
                        @click="openTask(card as TaskCard)"
                    >
                        დავალების ნახვა
                    </button>
                </template>
            </KanbanBoard>
        </section>

        <Dialog :open="submitDialog.open" @update:open="(open) => !open && cancelSubmitDialog()">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>დავალების გაგზავნა მისაღებად</DialogTitle>
                    <DialogDescription>{{ submitDialog.card?.title }}</DialogDescription>
                </DialogHeader>
                <Textarea v-model="submitDialog.comment" placeholder="კომენტარი (არასავალდებულო)" rows="3" />
                <DialogFooter>
                    <Button variant="outline" type="button" @click="cancelSubmitDialog">გაუქმება</Button>
                    <Button type="button" :disabled="submitDialog.processing" @click="confirmSubmit">გაგზავნა</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
