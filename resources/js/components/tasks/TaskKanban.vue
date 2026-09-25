<script setup lang="ts">
/**
 * The task Kanban board, with the mapping from a drag-drop move to the one
 * real Task Action it corresponds to.
 *
 * Audit A07: this used to live inline in Dashboard.vue, which is why the
 * project's own task screen was list-only while a link there promised
 * „დავალებების სრული სია და Kanban". Copying the board into a second page
 * would have meant two copies of the move rules — the part that decides
 * whether a drag starts work or submits it for acceptance — drifting apart.
 * It lives here once instead, and both screens render it.
 *
 * The board below (components/data/KanbanBoard.vue) is purely presentational:
 * it renders from `cardsByColumn` and never moves a card itself. So a
 * rejected move needs no revert — nothing moved until the server confirmed it
 * and the page reloaded.
 */
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import KanbanBoard from '@/components/data/KanbanBoard.vue';

export type TaskCard = {
    id: string;
    project_id: string;
    title: string;
    project_name?: string | null;
    status: string;
};

const props = withDefaults(
    defineProps<{
        tasks: TaskCard[];
        /** Props the page must re-fetch after a successful transition. */
        reloadOnly?: string[];
        /** Whether each card should name its project (cross-project boards). */
        showProjectName?: boolean;
    }>(),
    {
        reloadOnly: () => ['tasks'],
        showProjectName: true,
    },
);

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

// Reactive, not computed once: after a real transition the page reloads
// `tasks` and this must re-derive from the fresh prop rather than keep
// rendering the original snapshot forever.
const tasksByColumn = computed(() => {
    const byColumn: Record<string, TaskCard[]> = { todo: [], in_progress: [], done: [] };
    for (const task of props.tasks) {
        const column = TASK_KANBAN_COLUMN[task.status] ?? 'todo';
        byColumn[column].push(task);
    }

    return byColumn;
});

const moveError = ref<string | null>(null);
const submitDialog = ref<{ open: boolean; card: TaskCard | null; comment: string; processing: boolean }>({
    open: false,
    card: null,
    comment: '',
    processing: false,
});

function openTask(card: TaskCard) {
    router.visit(`/projects/${card.project_id}/tasks/${card.id}`);
}

function reloadBoard() {
    router.reload({ only: props.reloadOnly });
}

/**
 * Only two column pairs correspond to a single Task Action:
 *   todo (status=assigned)        -> in_progress : StartTask, no extra input
 *   in_progress (status=in_progress) -> done     : SubmitTaskForAcceptance
 * Everything else — a `blocked` or `draft` card, a backwards move, anything
 * that would need a reviewer's decision — is refused with an explicit
 * message. Never silently ignored, and never fabricated into some other
 * transition that happens to be reachable.
 */
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

    // A drag onto "done" can never itself accept the work — acceptance takes a
    // second, independent person (TM-01) and belongs on the task page.
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
    <div class="flex flex-col gap-3">
        <p v-if="moveError" class="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border px-3 py-2 text-sm">
            {{ moveError }}
        </p>

        <KanbanBoard :columns="kanbanColumns" :cards-by-column="tasksByColumn" @move="handleMove">
            <template #card="{ card }">
                <p class="text-foreground text-sm font-medium">{{ (card as TaskCard).title }}</p>
                <p v-if="showProjectName" class="text-muted-foreground text-xs">{{ (card as TaskCard).project_name }}</p>
            </template>
            <!-- #card-footer, not #card-actions: this button is about the
                 card, not about a destination column, so it renders once per
                 card rather than once per move target (A16). -->
            <template #card-footer="{ card }">
                <button
                    type="button"
                    class="border-border text-muted-foreground hover:bg-accent rounded-full border px-2 py-0.5 text-[11px]"
                    @click="openTask(card as TaskCard)"
                >
                    დავალების ნახვა
                </button>
            </template>
        </KanbanBoard>

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
