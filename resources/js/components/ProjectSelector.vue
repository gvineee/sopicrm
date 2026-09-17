<script setup lang="ts">
/**
 * Desktop shell project selector (spec 4: "პროექტის selector"). Presentational
 * by design — it receives the project list and current selection as props
 * and emits a change; the Projects module wires `projects` from a shared
 * Inertia prop (e.g. `usePage().props.projects`) and handles the actual
 * `@change` navigation/project-switch request once that endpoint exists
 * (see docs/decisions.md DEC-048 for why this stays presentational here).
 */
import { Building2, Check, ChevronsUpDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type ProjectOption = {
    id: string;
    name: string;
    code?: string;
};

const props = defineProps<{
    projects: ProjectOption[];
    modelValue: string | null;
}>();

const emit = defineEmits<{ 'update:modelValue': [string] }>();

const open = ref(false);
const current = computed(
    () => props.projects.find((p) => p.id === props.modelValue) ?? null,
);

function select(id: string) {
    emit('update:modelValue', id);
    open.value = false;
}
</script>

<template>
    <DropdownMenu v-model:open="open">
        <DropdownMenuTrigger
            class="border-sidebar-border bg-sidebar text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground flex h-9 min-w-40 items-center gap-2 rounded-md border px-3 text-sm"
            :aria-label="'პროექტის არჩევა'"
        >
            <Building2 class="size-4 shrink-0" aria-hidden="true" />
            <span class="truncate">{{
                current ? current.name : 'აირჩიეთ პროექტი'
            }}</span>
            <ChevronsUpDown
                class="ml-auto size-3.5 shrink-0 opacity-60"
                aria-hidden="true"
            />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-64">
            <DropdownMenuItem
                v-for="project in projects"
                :key="project.id"
                @select="select(project.id)"
            >
                <Check
                    :class="[
                        'size-3.5',
                        project.id === modelValue ? 'opacity-100' : 'opacity-0',
                    ]"
                    aria-hidden="true"
                />
                <span class="flex-1 truncate">{{ project.name }}</span>
                <span
                    v-if="project.code"
                    class="text-muted-foreground text-xs"
                    >{{ project.code }}</span
                >
            </DropdownMenuItem>
            <p
                v-if="projects.length === 0"
                class="text-muted-foreground px-2 py-3 text-sm"
            >
                პროექტები არ მოიძებნა
            </p>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
