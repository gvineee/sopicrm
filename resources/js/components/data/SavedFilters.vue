<script setup lang="ts">
import { Bookmark, Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { SavedFilter } from '@/types';

defineProps<{ savedFilters: SavedFilter[] }>();
const emit = defineEmits<{
    apply: [SavedFilter];
    save: [string];
    remove: [string];
}>();

const naming = ref(false);
const name = ref('');

function confirmSave() {
    if (!name.value.trim()) return;
    emit('save', name.value.trim());
    name.value = '';
    naming.value = false;
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <span
            v-for="filter in savedFilters"
            :key="filter.id"
            class="group border-border bg-secondary text-secondary-foreground inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs"
        >
            <button
                type="button"
                class="flex items-center gap-1"
                @click="emit('apply', filter)"
            >
                <Bookmark class="size-3" aria-hidden="true" />
                {{ filter.name }}
            </button>
            <button
                type="button"
                aria-label="ფილტრის წაშლა"
                class="opacity-50 hover:opacity-100"
                @click="emit('remove', filter.id)"
            >
                <X class="size-3" />
            </button>
        </span>

        <div v-if="naming" class="flex items-center gap-1">
            <Input
                v-model="name"
                placeholder="ფილტრის სახელი"
                class="h-7 w-36 text-xs"
                @keyup.enter="confirmSave"
                @keyup.escape="naming = false"
            />
            <Button
                size="sm"
                variant="ghost"
                class="h-7 px-2"
                @click="confirmSave"
                >კარგი</Button
            >
        </div>
        <Button
            v-else
            size="sm"
            variant="ghost"
            class="h-7 px-2 text-xs"
            @click="naming = true"
        >
            <Plus class="size-3" aria-hidden="true" />
            ფილტრის შენახვა
        </Button>
    </div>
</template>
