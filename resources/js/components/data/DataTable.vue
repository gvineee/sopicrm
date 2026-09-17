<script setup lang="ts" generic="TRow extends Record<string, unknown>">
/**
 * Server-paginated/sorted/filtered data table primitive (spec 4: "ფილტრიანი
 * data tables"; spec 17: wide tables need mobile cards or controlled
 * scroll, never uncontrolled horizontal page scroll). This component is
 * presentational — it renders `rows` as given and emits sort-header clicks;
 * the actual page/sort/filter request round-trip lives in
 * composables/useServerTable.ts so every module's table hits the server the
 * same way instead of re-deriving pagination logic per screen.
 *
 * Column cells: pass a `#cell-<key>="{ row }"` slot to customize rendering
 * (status badges, links, money formatting, …); columns without a matching
 * slot render `row[key]` as plain text.
 *
 * Below `sm`, if the consumer provides a `#mobile-card="{ row }"` slot,
 * that renders instead of the table (own composition, not a shrunk table —
 * spec 4). Otherwise the table stays and only its own container scrolls
 * horizontally (`overflow-x-auto`), never the page.
 */
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue';
import EmptyState from '@/components/states/EmptyState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import { cn } from '@/lib/utils';
import type { ColumnDef, SortState } from '@/types';

const props = withDefaults(
    defineProps<{
        columns: ColumnDef[];
        rows: TRow[];
        rowKey: (row: TRow) => string | number;
        sort?: SortState;
        loading?: boolean;
        emptyTitle?: string;
        emptyDescription?: string;
    }>(),
    {
        sort: null,
        loading: false,
        emptyTitle: 'მონაცემები არ მოიძებნა',
    },
);

const emit = defineEmits<{ 'update:sort': [SortState]; rowClick: [TRow] }>();

function toggleSort(column: ColumnDef) {
    if (!column.sortable) return;

    if (props.sort?.key !== column.key) {
        emit('update:sort', { key: column.key, direction: 'asc' });
        return;
    }

    emit(
        'update:sort',
        props.sort.direction === 'asc'
            ? { key: column.key, direction: 'desc' }
            : null,
    );
}

function hideClass(column: ColumnDef) {
    if (column.hideBelow === 'sm') return 'hidden sm:table-cell';
    if (column.hideBelow === 'md') return 'hidden md:table-cell';
    if (column.hideBelow === 'lg') return 'hidden lg:table-cell';
    return '';
}
</script>

<template>
    <LoadingState v-if="loading" variant="rows" />

    <EmptyState
        v-else-if="rows.length === 0"
        :title="emptyTitle"
        :description="emptyDescription"
    />

    <template v-else>
        <div v-if="$slots['mobile-card']" class="grid gap-2 sm:hidden">
            <div
                v-for="row in rows"
                :key="rowKey(row)"
                @click="emit('rowClick', row)"
            >
                <slot name="mobile-card" :row="row" />
            </div>
        </div>

        <div
            :class="
                cn(
                    'border-border overflow-x-auto rounded-xl border',
                    $slots['mobile-card'] && 'hidden sm:block',
                )
            "
        >
            <table class="w-full min-w-max text-sm">
                <thead class="border-border bg-muted/50 border-b">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="
                                cn(
                                    'text-muted-foreground px-3 py-2 text-left font-medium',
                                    column.widthClass,
                                    hideClass(column),
                                    column.align === 'end' && 'text-right',
                                    column.align === 'center' && 'text-center',
                                )
                            "
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="hover:text-foreground inline-flex items-center gap-1"
                                @click="toggleSort(column)"
                            >
                                {{ column.header }}
                                <ArrowUp
                                    v-if="
                                        sort?.key === column.key &&
                                        sort.direction === 'asc'
                                    "
                                    class="size-3"
                                />
                                <ArrowDown
                                    v-else-if="
                                        sort?.key === column.key &&
                                        sort.direction === 'desc'
                                    "
                                    class="size-3"
                                />
                                <ArrowUpDown v-else class="size-3 opacity-40" />
                            </button>
                            <template v-else>{{ column.header }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="rowKey(row)"
                        class="border-border hover:bg-accent/40 cursor-pointer border-b last:border-0"
                        @click="emit('rowClick', row)"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :class="
                                cn(
                                    'text-foreground px-3 py-2.5',
                                    hideClass(column),
                                    column.align === 'end' && 'text-right',
                                    column.align === 'center' && 'text-center',
                                )
                            "
                        >
                            <slot :name="`cell-${column.key}`" :row="row">
                                {{ row[column.key] }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </template>
</template>
