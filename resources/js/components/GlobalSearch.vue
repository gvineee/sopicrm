<script setup lang="ts">
/**
 * Global search (spec 4: "global search"). Search itself is server-side per
 * spec 4 ("ძიება... სერვერულია") — this component owns only the UI
 * (trigger, dialog, keyboard shortcut, results list) and debounced query
 * emission; the consuming layout wires `@query` to whatever module owns the
 * cross-entity search endpoint once one exists (not yet built — see
 * docs/decisions.md DEC-048) and passes results back in as `results`.
 */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Search } from '@lucide/vue';
import { onMounted, onUnmounted, ref, watch } from 'vue';

export type SearchResult = {
    id: string;
    title: string;
    subtitle?: string;
    href: string;
    group?: string;
};

const props = withDefaults(
    defineProps<{
        results?: SearchResult[];
        loading?: boolean;
    }>(),
    {
        results: () => [],
        loading: false,
    },
);

const emit = defineEmits<{ query: [string] }>();

const open = ref(false);
const query = ref('');
let debounceHandle: ReturnType<typeof setTimeout> | undefined;

watch(query, (value) => {
    clearTimeout(debounceHandle);
    debounceHandle = setTimeout(() => emit('query', value), 200);
});

function handleShortcut(event: KeyboardEvent) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = true;
    }
}

onMounted(() => window.addEventListener('keydown', handleShortcut));
onUnmounted(() => window.removeEventListener('keydown', handleShortcut));

defineExpose({ open });
void props;
</script>

<template>
    <button
        type="button"
        class="border-sidebar-border bg-sidebar text-muted-foreground hover:bg-sidebar-accent flex h-9 w-full max-w-64 items-center gap-2 rounded-md border px-3 text-sm"
        @click="open = true"
    >
        <Search class="size-4" aria-hidden="true" />
        <span>ძიება…</span>
        <kbd
            class="border-border bg-muted ml-auto rounded border px-1.5 py-0.5 text-[10px]"
            >⌘K</kbd
        >
    </button>

    <Dialog v-model:open="open">
        <DialogContent class="top-24 max-w-lg translate-y-0 gap-0 p-0">
            <DialogTitle class="sr-only">გლობალური ძიება</DialogTitle>
            <DialogDescription class="sr-only">
                მოძებნეთ პროექტები, დავალებები, თანამშრომლები და სხვა.
            </DialogDescription>
            <div
                class="border-border flex items-center gap-2 border-b px-4 py-3"
            >
                <Search
                    class="text-muted-foreground size-4"
                    aria-hidden="true"
                />
                <input
                    v-model="query"
                    type="text"
                    autofocus
                    placeholder="მოძებნეთ პროექტები, დავალებები, თანამშრომლები…"
                    class="placeholder:text-muted-foreground w-full bg-transparent text-sm outline-none"
                />
            </div>
            <div class="max-h-80 overflow-y-auto p-2" role="listbox">
                <p
                    v-if="loading"
                    class="text-muted-foreground px-2 py-4 text-sm"
                >
                    იძებნება…
                </p>
                <p
                    v-else-if="query && results.length === 0"
                    class="text-muted-foreground px-2 py-4 text-sm"
                >
                    შედეგი ვერ მოიძებნა „{{ query }}“-სთვის
                </p>
                <a
                    v-for="result in results"
                    :key="result.id"
                    :href="result.href"
                    class="hover:bg-accent hover:text-accent-foreground block rounded-md px-2 py-2 text-sm"
                    role="option"
                >
                    <span class="text-foreground block font-medium">{{
                        result.title
                    }}</span>
                    <span
                        v-if="result.subtitle"
                        class="text-muted-foreground block text-xs"
                    >
                        {{ result.subtitle }}
                    </span>
                </a>
            </div>
        </DialogContent>
    </Dialog>
</template>
