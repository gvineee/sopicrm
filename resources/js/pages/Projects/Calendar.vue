<script setup lang="ts">
/**
 * PROJECT-01: real task-by-due-date calendar, backed by
 * App\Http\Controllers\DashboardController::calendar() — the exact same
 * project-membership + TaskPolicy::scopeVisibleToPerformer() visibility
 * scope as the Dashboard/Kanban board, never a second, looser query. A
 * plain month-grid built with plain CSS grid, no calendar library
 * dependency (this codebase's established preference — see
 * docs/decisions.md DEC-047 for the equivalent "no wrapper library for a
 * buildable primitive" reasoning applied elsewhere).
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import type { StatusTone } from '@/types';

type CalendarTask = {
    id: string;
    project_id: string;
    title: string;
    project_name?: string | null;
    status: string;
    due_at: string;
};

const props = defineProps<{
    month: string;
    tasks: CalendarTask[];
}>();

defineOptions({ layout: { mobileTitle: 'კალენდარი' } });

const STATUS_LABEL: Record<string, string> = {
    draft: 'შავი ვარიანტი',
    assigned: 'მინიჭებული',
    in_progress: 'მიმდინარეობს',
    blocked: 'დაბლოკილი',
    submitted: 'გაგზავნილია',
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

const WEEKDAY_LABELS = ['ორშ', 'სამ', 'ოთხ', 'ხუთ', 'პარ', 'შაბ', 'კვ'];

const anchor = computed(() => new Date(`${props.month}T00:00:00`));

const monthLabel = computed(() =>
    anchor.value.toLocaleDateString('ka-GE', { year: 'numeric', month: 'long' }),
);

const tasksByDate = computed(() => {
    const map: Record<string, CalendarTask[]> = {};
    for (const task of props.tasks) {
        (map[task.due_at] ??= []).push(task);
    }

    return map;
});

// Monday-first month grid, including the leading/trailing days of the
// adjacent months needed to fill full weeks.
const gridDays = computed(() => {
    const year = anchor.value.getFullYear();
    const month = anchor.value.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    const startWeekday = (firstOfMonth.getDay() + 6) % 7; // 0 = Monday
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    const days: { date: string; day: number; inMonth: boolean }[] = [];

    for (let i = 0; i < startWeekday; i++) {
        const d = new Date(year, month, 1 - (startWeekday - i));
        days.push({ date: isoDate(d), day: d.getDate(), inMonth: false });
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const d = new Date(year, month, day);
        days.push({ date: isoDate(d), day, inMonth: true });
    }

    while (days.length % 7 !== 0) {
        const last = new Date(days[days.length - 1].date);
        const d = new Date(last.getFullYear(), last.getMonth(), last.getDate() + 1);
        days.push({ date: isoDate(d), day: d.getDate(), inMonth: false });
    }

    return days;
});

function isoDate(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function goToMonth(offset: number) {
    const d = new Date(anchor.value.getFullYear(), anchor.value.getMonth() + offset, 1);
    router.get('/tasks-calendar', { month: isoDate(d).slice(0, 7) }, { preserveState: true });
}

function openTask(task: CalendarTask) {
    router.visit(`/projects/${task.project_id}/tasks/${task.id}`);
}
</script>

<template>
    <Head title="დავალებების კალენდარი" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-foreground text-xl font-semibold">დავალებების კალენდარი</h1>
                <p class="text-muted-foreground text-sm">დავალებები დასრულების ვადის მიხედვით.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="border-border hover:bg-accent rounded-lg border p-1.5" @click="goToMonth(-1)">
                    <ChevronLeft class="size-4" />
                </button>
                <span class="min-w-32 text-center text-sm font-medium capitalize">{{ monthLabel }}</span>
                <button type="button" class="border-border hover:bg-accent rounded-lg border p-1.5" @click="goToMonth(1)">
                    <ChevronRight class="size-4" />
                </button>
            </div>
        </div>

        <div class="grid grid-cols-7 gap-px overflow-hidden rounded-lg border text-center text-xs">
            <div v-for="label in WEEKDAY_LABELS" :key="label" class="bg-muted text-muted-foreground py-1.5 font-medium">
                {{ label }}
            </div>

            <div
                v-for="cell in gridDays"
                :key="cell.date"
                :class="[
                    'bg-card flex min-h-24 flex-col gap-1 p-1.5 text-left align-top',
                    !cell.inMonth && 'bg-muted/30 text-muted-foreground',
                ]"
            >
                <span class="text-xs font-medium">{{ cell.day }}</span>
                <button
                    v-for="task in (tasksByDate[cell.date] ?? []).slice(0, 3)"
                    :key="task.id"
                    type="button"
                    class="hover:bg-accent flex items-center gap-1 rounded px-1 py-0.5 text-left text-[11px]"
                    @click="openTask(task)"
                >
                    <StatusBadge :label="STATUS_LABEL[task.status] || task.status" :tone="STATUS_TONE[task.status] || 'neutral'" class="shrink-0 scale-90" />
                    <span class="truncate">{{ task.title }}</span>
                </button>
                <span v-if="(tasksByDate[cell.date] ?? []).length > 3" class="text-muted-foreground px-1 text-[11px]">
                    +{{ (tasksByDate[cell.date] ?? []).length - 3 }} სხვა
                </span>
            </div>
        </div>

        <p v-if="tasks.length === 0" class="text-muted-foreground text-center text-sm">ამ თვეს ვადიანი დავალება არ არის.</p>

        <Link href="/dashboard" class="text-primary text-sm hover:underline">← მიმოხილვაზე დაბრუნება</Link>
    </div>
</template>
