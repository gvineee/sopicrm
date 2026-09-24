<script setup lang="ts">
/**
 * Server-side searchable selector for a record identifier (spec 02 §10:
 * "იდენტიფიკატორის არჩევა სერვერული საძიებო სელექტორით"; audit A12/A13).
 *
 * The operator searches by name and picks a row; the UUID travels in the
 * form payload and is never shown, typed or pasted. `endpoint` must be a
 * JSON route that is authorized for exactly the screen using it and returns
 * `{ options: [{ id, label, sublabel? }] }` — this component deliberately
 * has no idea which table it is searching, so the scoping stays server-side
 * where it can be enforced.
 *
 * A picked value alone is never trusted: the receiving FormRequest must
 * re-validate the id against the same tenant-scoped set (hiding a field is
 * not an authorization boundary).
 */
import { Check, ChevronsUpDown, Loader2, X } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';

export type EntityOption = { id: string; label: string; sublabel?: string | null };

const props = withDefaults(
    defineProps<{
        id: string;
        endpoint: string;
        modelValue: string;
        selectedLabel?: string | null;
        placeholder?: string;
        emptyText?: string;
        disabled?: boolean;
    }>(),
    {
        selectedLabel: null,
        placeholder: 'მოძებნეთ და აირჩიეთ',
        emptyText: 'შედეგი ვერ მოიძებნა',
        disabled: false,
    },
);

const emit = defineEmits<{ 'update:modelValue': [string]; 'update:selectedLabel': [string | null] }>();

const open = ref(false);
const query = ref('');
const options = ref<EntityOption[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);
const activeIndex = ref(-1);
const chosenLabel = ref<string | null>(props.selectedLabel);
const searchInput = ref<HTMLInputElement | null>(null);

// Edit forms receive the already-chosen row's label from the server after a
// reload; without this the field would fall back to showing nothing for a
// value that is in fact set.
watch(
    () => props.selectedLabel,
    (label) => {
        chosenLabel.value = label;
    },
);

let debounce: ReturnType<typeof setTimeout> | undefined;
let inFlight: AbortController | undefined;

async function search(term: string) {
    inFlight?.abort();
    const controller = new AbortController();
    inFlight = controller;

    loading.value = true;
    loadError.value = null;

    try {
        // `endpoint` may already carry query parameters of its own (the
        // asset location selector passes `?type=site`).
        const separator = props.endpoint.includes('?') ? '&' : '?';
        const response = await fetch(`${props.endpoint}${separator}q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (!response.ok) {
            // Deliberately generic: the server's own body may carry detail
            // that does not belong on screen (spec 02 §21).
            throw new Error('lookup failed');
        }

        const payload = (await response.json()) as { options?: EntityOption[] };
        options.value = payload.options ?? [];
        activeIndex.value = options.value.length > 0 ? 0 : -1;
    } catch (error) {
        if (controller.signal.aborted) {
            return;
        }
        options.value = [];
        loadError.value = 'სიის ჩატვირთვა ვერ მოხერხდა. სცადეთ თავიდან.';
    } finally {
        if (!controller.signal.aborted) {
            loading.value = false;
        }
    }
}

function scheduleSearch(term: string) {
    clearTimeout(debounce);
    debounce = setTimeout(() => void search(term), 250);
}

async function openPanel() {
    if (props.disabled) {
        return;
    }
    open.value = true;
    await nextTick();
    searchInput.value?.focus();
    void search(query.value);
}

function closePanel() {
    open.value = false;
    query.value = '';
    activeIndex.value = -1;
}

function choose(option: EntityOption) {
    chosenLabel.value = option.label;
    emit('update:modelValue', option.id);
    emit('update:selectedLabel', option.label);
    closePanel();
}

function clear() {
    chosenLabel.value = null;
    emit('update:modelValue', '');
    emit('update:selectedLabel', null);
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        closePanel();
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, options.value.length - 1);
        return;
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
        return;
    }
    if (event.key === 'Enter') {
        event.preventDefault();
        const option = options.value[activeIndex.value];
        if (option) {
            choose(option);
        }
    }
}
</script>

<template>
    <div class="relative">
        <div class="flex items-center gap-1">
            <button
                :id="id"
                type="button"
                role="combobox"
                :aria-expanded="open"
                :aria-controls="`${id}-listbox`"
                :disabled="disabled"
                class="border-input bg-background flex h-9 flex-1 items-center gap-2 rounded-md border px-3 text-left text-sm disabled:opacity-50"
                @click="open ? closePanel() : openPanel()"
            >
                <span :class="['flex-1 truncate', chosenLabel ? '' : 'text-muted-foreground']">
                    {{ chosenLabel || placeholder }}
                </span>
                <ChevronsUpDown class="size-3.5 shrink-0 opacity-60" aria-hidden="true" />
            </button>
            <button
                v-if="chosenLabel && !disabled"
                type="button"
                class="text-muted-foreground hover:bg-accent rounded-md p-2"
                aria-label="არჩევანის გასუფთავება"
                @click="clear"
            >
                <X class="size-3.5" aria-hidden="true" />
            </button>
        </div>

        <div v-if="open" class="border-border bg-popover absolute z-50 mt-1 w-full rounded-md border shadow-md">
            <div class="border-border border-b p-2">
                <input
                    ref="searchInput"
                    v-model="query"
                    type="text"
                    class="border-input bg-background h-8 w-full rounded-md border px-2 text-sm"
                    :placeholder="placeholder"
                    autocomplete="off"
                    @input="scheduleSearch(query)"
                    @keydown="onKeydown"
                />
            </div>

            <p v-if="loading" class="text-muted-foreground flex items-center gap-2 px-3 py-3 text-sm">
                <Loader2 class="size-3.5 animate-spin" aria-hidden="true" />
                იტვირთება…
            </p>
            <p v-else-if="loadError" class="text-destructive px-3 py-3 text-sm">{{ loadError }}</p>
            <p v-else-if="options.length === 0" class="text-muted-foreground px-3 py-3 text-sm">{{ emptyText }}</p>

            <ul v-else :id="`${id}-listbox`" role="listbox" class="max-h-56 overflow-y-auto py-1">
                <li v-for="(option, index) in options" :key="option.id" role="option" :aria-selected="option.id === modelValue">
                    <button
                        type="button"
                        :class="[
                            'flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm',
                            index === activeIndex ? 'bg-accent' : '',
                        ]"
                        @click="choose(option)"
                        @mouseenter="activeIndex = index"
                    >
                        <Check :class="['size-3.5 shrink-0', option.id === modelValue ? 'opacity-100' : 'opacity-0']" aria-hidden="true" />
                        <span class="flex-1 truncate">{{ option.label }}</span>
                        <span v-if="option.sublabel" class="text-muted-foreground shrink-0 text-xs">{{ option.sublabel }}</span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
