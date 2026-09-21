<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import EmptyState from '@/components/states/EmptyState.vue';

type PositionRow = {
    id: string;
    name: string;
    is_active: boolean;
    employees_count: number;
};

const props = defineProps<{
    positions: PositionRow[];
    canManage: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'პოზიციები' } });

const createForm = useForm({ name: '', is_active: true });

function createPosition() {
    createForm.post('/positions', { preserveScroll: true, onSuccess: () => createForm.reset() });
}

const editForms = reactive<Record<string, ReturnType<typeof useForm<{ name: string; is_active: boolean }>>>>({});
function editFormFor(position: PositionRow) {
    if (!editForms[position.id]) {
        editForms[position.id] = useForm({ name: position.name, is_active: position.is_active });
    }
    return editForms[position.id];
}

function savePosition(position: PositionRow) {
    editFormFor(position).patch(`/positions/${position.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="პოზიციები" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/employees" class="text-muted-foreground text-sm hover:underline">← თანამშრომლები</Link>
            <h1 class="mt-2 text-2xl font-semibold">პოზიციები</h1>
            <p class="text-muted-foreground text-sm">
                წინასწარ განსაზღვრული სამუშაო პოზიციები — გამოიყენება თანამშრომლის პროფილში და ფილტრებში.
            </p>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="positions.length === 0" title="პოზიცია ჯერ არ არის დამატებული" description="დაამატეთ პირველი პოზიცია ქვემოთ მოცემული ფორმით." />
            <div v-else class="divide-border divide-y">
                <div v-for="position in positions" :key="position.id" class="flex flex-wrap items-center gap-3 p-4">
                    <Input v-model="editFormFor(position).name" class="min-w-0 flex-1" :disabled="!canManage" />
                    <label class="flex items-center gap-2 text-sm whitespace-nowrap">
                        <input v-model="editFormFor(position).is_active" type="checkbox" :disabled="!canManage" />
                        აქტიური
                    </label>
                    <span class="text-muted-foreground text-xs whitespace-nowrap">{{ position.employees_count }} თანამშრომელი</span>
                    <Button v-if="canManage" size="sm" variant="outline" :disabled="editFormFor(position).processing" @click="savePosition(position)">
                        შენახვა
                    </Button>
                    <p v-if="editFormFor(position).errors.name" class="text-destructive w-full text-sm">{{ editFormFor(position).errors.name }}</p>
                </div>
            </div>
        </div>

        <form v-if="canManage" class="border-border bg-card grid gap-3 rounded-xl border p-4 sm:grid-cols-[1fr_auto]" @submit.prevent="createPosition">
            <Input v-model="createForm.name" required placeholder="ახალი პოზიციის დასახელება" />
            <Button type="submit" :disabled="createForm.processing">დამატება</Button>
            <p v-if="createForm.errors.name" class="text-destructive text-sm sm:col-span-2">{{ createForm.errors.name }}</p>
        </form>
    </div>
</template>
