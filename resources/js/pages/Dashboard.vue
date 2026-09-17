<script setup lang="ts">
/**
 * Desktop dashboard — P0 visual foundation (spec section 4).
 *
 * All data below is static placeholder content, clearly scoped as such:
 * this page proves out the design-system primitives (KpiTile, DataTable,
 * KanbanBoard, StatusBadge, the loading/empty/error states) at real
 * viewport widths (see docs/decisions.md DEC-050 for how 360/390/768/1440px
 * were verified). It is NOT a claim that Projects/Tasks data is wired to a
 * real API yet — spec 4's own words: "ვიზუალური პროტოტიპი მხოლოდ
 * შუალედური შედეგია; საბოლოო UI რეალურ API-ს იყენებს." The Projects/Tasks
 * modules replace `demoProjects`/`demoTasks` with real Inertia props and
 * `useServerTable`-driven requests without needing to change this page's
 * layout.
 */
import { Head } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    Clock,
    HardHat,
    Wrench,
} from '@lucide/vue';
import { ref } from 'vue';
import KpiTile from '@/components/KpiTile.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DataTable from '@/components/data/DataTable.vue';
import KanbanBoard from '@/components/data/KanbanBoard.vue';
import type { ColumnDef, StatusDescriptor } from '@/types';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'მიმოხილვა', href: dashboard() }],
    },
});

type DemoProject = {
    id: string;
    name: string;
    manager: string;
    status: StatusDescriptor;
    dueDate: string;
};

const demoProjects: DemoProject[] = [
    {
        id: 'p1',
        name: 'საცხოვრებელი კომპლექსი „მთაწმინდა 12“',
        manager: 'გ. კვარაცხელია',
        status: { label: 'მიმდინარეობს', tone: 'info', icon: Clock },
        dueDate: '2026-11-30',
    },
    {
        id: 'p2',
        name: 'საოფისე შენობა — ვაკე',
        manager: 'ნ. ბერიძე',
        status: {
            label: 'დაგვიანებულია',
            tone: 'warning',
            icon: AlertTriangle,
        },
        dueDate: '2026-09-20',
    },
    {
        id: 'p3',
        name: 'სავაჭრო ცენტრის რემონტი',
        manager: 'დ. მაისურაძე',
        status: { label: 'დასრულებულია', tone: 'success', icon: CheckCircle2 },
        dueDate: '2026-08-05',
    },
];

const projectColumns: ColumnDef[] = [
    { key: 'name', header: 'პროექტი', sortable: true },
    { key: 'manager', header: 'მენეჯერი', sortable: true, hideBelow: 'md' },
    { key: 'status', header: 'სტატუსი', sortable: true, hideBelow: 'sm' },
    {
        key: 'dueDate',
        header: 'ვადა',
        sortable: true,
        align: 'end',
        hideBelow: 'sm',
    },
];

type DemoTask = { id: string; title: string; project: string };

const kanbanColumns = [
    { key: 'todo', title: 'დასაწყები' },
    { key: 'in_progress', title: 'მიმდინარე' },
    { key: 'done', title: 'დასრულებული' },
];

const tasksByColumn = ref<Record<string, DemoTask[]>>({
    todo: [
        { id: 't1', title: 'არმატურის მიწოდება', project: 'მთაწმინდა 12' },
        { id: 't2', title: 'ელექტროგეგმის შეთანხმება', project: 'ვაკე' },
    ],
    in_progress: [
        {
            id: 't3',
            title: 'ბეტონის ჩასხმა — 3 სართული',
            project: 'მთაწმინდა 12',
        },
    ],
    done: [
        { id: 't4', title: 'საძირკვლის შემოწმება', project: 'სავაჭრო ცენტრი' },
    ],
});

function moveTask({ card, toColumn }: { card: DemoTask; toColumn: string }) {
    for (const key of Object.keys(tasksByColumn.value)) {
        tasksByColumn.value[key] = tasksByColumn.value[key].filter(
            (t) => t.id !== card.id,
        );
    }
    tasksByColumn.value[toColumn] = [
        ...(tasksByColumn.value[toColumn] ?? []),
        card,
    ];
}
</script>

<template>
    <Head title="მიმოხილვა" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div>
            <h1 class="text-foreground text-xl font-semibold">მიმოხილვა</h1>
            <p class="text-muted-foreground text-sm">
                ორგანიზაციის საერთო სურათი დღეისთვის.
            </p>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <KpiTile
                label="აქტიური პროექტები"
                value="12"
                :icon="Building2"
                href="#"
                delta="+2"
                delta-tone="positive"
            />
            <KpiTile
                label="დღეს დასწრებაზე"
                value="86"
                :icon="HardHat"
                href="#"
                delta="-4"
                delta-tone="negative"
            />
            <KpiTile label="ღია დავალებები" value="34" :icon="Clock" href="#" />
            <KpiTile
                label="ხელსაწყოს ვადაგადაცილება"
                value="3"
                :icon="Wrench"
                href="#"
                delta-tone="negative"
                delta="+1"
            />
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="text-muted-foreground text-sm font-medium">პროექტები</h2>
            <DataTable
                :columns="projectColumns"
                :rows="demoProjects"
                :row-key="(row) => row.id"
            >
                <template #cell-status="{ row }">
                    <StatusBadge
                        :label="row.status.label"
                        :tone="row.status.tone"
                        :icon="row.status.icon"
                    />
                </template>
                <template #mobile-card="{ row }">
                    <div class="border-border bg-card rounded-xl border p-4">
                        <div
                            class="flex flex-wrap items-start justify-between gap-2"
                        >
                            <p
                                class="text-foreground min-w-0 flex-1 text-base font-medium break-words"
                            >
                                {{ row.name }}
                            </p>
                            <StatusBadge
                                class="shrink-0"
                                :label="row.status.label"
                                :tone="row.status.tone"
                                :icon="row.status.icon"
                            />
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ row.manager }} · {{ row.dueDate }}
                        </p>
                    </div>
                </template>
            </DataTable>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-muted-foreground text-sm font-medium">
                დავალებების დაფა
            </h2>
            <KanbanBoard
                :columns="kanbanColumns"
                :cards-by-column="tasksByColumn"
                @move="moveTask"
            >
                <template #card="{ card }">
                    <p class="text-foreground text-sm font-medium">
                        {{ card.title }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{ card.project }}
                    </p>
                </template>
                <template #card-actions="{ move, target }">
                    <button
                        type="button"
                        class="border-border text-muted-foreground hover:bg-accent rounded-full border px-2 py-0.5 text-[11px]"
                        @click="move()"
                    >
                        → {{ target.title }}
                    </button>
                </template>
            </KanbanBoard>
        </section>
    </div>
</template>
