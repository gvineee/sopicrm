<script setup lang="ts" generic="TCard extends { id: string | number }">
/**
 * Kanban primitive (spec 4: "Kanban"). Native HTML5 drag-and-drop — no
 * extra dependency — with a keyboard-accessible fallback: each card also
 * exposes "move to <column>" buttons via the `#card-actions` slot contract
 * so dragging is never the only way to change a card's column (touch
 * devices and keyboard users need it too).
 *
 * Presentational: emits `move` with the card and its destination column
 * key; the consumer performs the real server update (and reverts optimistic
 * state on failure/permission-denied/version-conflict).
 */
import { cn } from '@/lib/utils';
import { shallowRef } from 'vue';
import type { ShallowRef } from 'vue';

export type KanbanColumn = { key: string; title: string };

defineProps<{
    columns: KanbanColumn[];
    cardsByColumn: Record<string, TCard[]>;
}>();

const emit = defineEmits<{ move: [{ card: TCard; toColumn: string }] }>();

// Explicit `Ref<...>` cast: TS's generic-distribution over a type param
// inside shallowRef()'s own conditional return type otherwise rejects
// assigning a plain TCard to `.value` here — see vuejs/core#3948-style
// generic-ref inference limitations. This asserts the intended, correct
// type directly instead of fighting the inferred one.
const draggingCard = shallowRef<TCard | null>(null) as ShallowRef<TCard | null>;
const dragOverColumn = shallowRef<string | null>(null) as ShallowRef<
    string | null
>;

function onDrop(columnKey: string) {
    if (draggingCard.value) {
        emit('move', { card: draggingCard.value, toColumn: columnKey });
    }
    draggingCard.value = null;
    dragOverColumn.value = null;
}
</script>

<template>
    <div class="flex gap-3 overflow-x-auto pb-2">
        <div
            v-for="column in columns"
            :key="column.key"
            :class="
                cn(
                    'border-border bg-muted/40 flex w-72 shrink-0 flex-col rounded-xl border p-2',
                    dragOverColumn === column.key && 'ring-primary ring-2',
                )
            "
            @dragover.prevent="dragOverColumn = column.key"
            @dragleave="dragOverColumn = null"
            @drop.prevent="onDrop(column.key)"
        >
            <div class="flex items-center justify-between px-2 py-1.5">
                <h3 class="text-foreground text-sm font-medium">
                    {{ column.title }}
                </h3>
                <span class="text-muted-foreground text-xs">
                    {{ (cardsByColumn[column.key] ?? []).length }}
                </span>
            </div>

            <div class="flex flex-col gap-2">
                <div
                    v-for="card in cardsByColumn[column.key] ?? []"
                    :key="card.id"
                    draggable="true"
                    class="border-border bg-card cursor-grab rounded-lg border p-3 shadow-sm active:cursor-grabbing"
                    @dragstart="draggingCard = card"
                    @dragend="draggingCard = null"
                >
                    <slot name="card" :card="card" />
                    <div class="mt-2 flex flex-wrap gap-1 empty:hidden">
                        <slot
                            v-for="target in columns.filter(
                                (c) => c.key !== column.key,
                            )"
                            :key="target.key"
                            name="card-actions"
                            :card="card"
                            :move="
                                () =>
                                    emit('move', { card, toColumn: target.key })
                            "
                            :target="target"
                        />
                    </div>
                </div>

                <p
                    v-if="(cardsByColumn[column.key] ?? []).length === 0"
                    class="border-border text-muted-foreground rounded-lg border border-dashed px-2 py-6 text-center text-xs"
                >
                    ცარიელია
                </p>
            </div>
        </div>
    </div>
</template>
