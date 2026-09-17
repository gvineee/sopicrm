<script setup lang="ts">
/**
 * Server-side filter bar (spec 4: "ძიება, სორტირება და ფილტრები
 * სერვერულია"). Emits `update:values` on every change; the page wires that
 * to an Inertia `router.get(..., { preserveState: true })` call (see
 * composables/useServerTable.ts) rather than filtering client-side.
 */
import { Search, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { FilterFieldDef, FilterValues } from '@/types';

const props = defineProps<{
    fields: FilterFieldDef[];
    values: FilterValues;
}>();

const emit = defineEmits<{ 'update:values': [FilterValues] }>();

function setValue(key: string, value: FilterValues[string]) {
    emit('update:values', { ...props.values, [key]: value });
}

function clearAll() {
    const cleared: FilterValues = {};
    for (const field of props.fields) cleared[field.key] = null;
    emit('update:values', cleared);
}

const hasActiveFilters = () =>
    Object.values(props.values).some((v) => v !== null && v !== '');
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <template v-for="field in fields" :key="field.key">
            <div v-if="field.type === 'text'" class="relative w-full max-w-56">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    :model-value="(values[field.key] as string) ?? ''"
                    :placeholder="field.placeholder ?? field.label"
                    class="pl-8"
                    @update:model-value="(v) => setValue(field.key, String(v))"
                />
            </div>

            <Select
                v-else-if="field.type === 'select'"
                :model-value="(values[field.key] as string) ?? undefined"
                @update:model-value="
                    (v) => setValue(field.key, v == null ? null : String(v))
                "
            >
                <SelectTrigger class="w-44">
                    <SelectValue :placeholder="field.label" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in field.options"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </template>

        <Button
            v-if="hasActiveFilters()"
            variant="ghost"
            size="sm"
            @click="clearAll"
        >
            <X class="size-3.5" aria-hidden="true" />
            გასუფთავება
        </Button>

        <slot name="trailing" />
    </div>
</template>
