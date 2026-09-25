<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { formatDateTime } from '@/lib/labels';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import type { StatusTone } from '@/types';

type Mapping = {
    id: string;
    source_system: string;
    external_type: string;
    external_identifier: string;
    status: string;
    first_seen_at: string;
    confirmed_by: string | null;
    confirmed_at: string | null;
    note: string | null;
    event_count: number;
    first_event_at: string | null;
    last_event_at: string | null;
    sample_device_serial: string | null;
};

type PaginatedMappings = {
    data: Mapping[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    mappings: PaginatedMappings;
    includeResolved: boolean;
    employees: Array<{ id: string; first_name: string; last_name: string }>;
    canManage: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'უცნობი ბარათები' } });

const STATUS_TONE: Record<string, StatusTone> = {
    pending: 'warning',
    confirmed: 'success',
    ignored: 'neutral',
};
const STATUS_LABEL: Record<string, string> = {
    pending: 'გადასამოწმებელი',
    confirmed: 'დადასტურებული',
    ignored: 'იგნორირებული',
};

function toggleResolved() {
    router.get('/device-external-mappings', { include_resolved: props.includeResolved ? undefined : '1' }, { preserveScroll: true });
}

const openConfirm = reactive<Record<string, boolean>>({});
const confirmForms = reactive<
    Record<string, ReturnType<typeof useForm<{ employee_id: string; valid_from: string }>>>
>({});
const ignoreForms = reactive<Record<string, ReturnType<typeof useForm<{ note: string }>>>>({});

function confirmFormFor(mappingId: string) {
    if (!confirmForms[mappingId]) {
        confirmForms[mappingId] = useForm({ employee_id: '', valid_from: '' });
    }
    return confirmForms[mappingId];
}

function ignoreFormFor(mappingId: string) {
    if (!ignoreForms[mappingId]) {
        ignoreForms[mappingId] = useForm({ note: '' });
    }
    return ignoreForms[mappingId];
}

function submitConfirm(mappingId: string) {
    confirmFormFor(mappingId)
        .transform((data) => ({ ...data, valid_from: data.valid_from || null }))
        .post(`/device-external-mappings/${mappingId}/confirm`, {
            preserveScroll: true,
            onSuccess: () => {
                openConfirm[mappingId] = false;
            },
        });
}

function submitIgnore(mappingId: string) {
    ignoreFormFor(mappingId).post(`/device-external-mappings/${mappingId}/ignore`, { preserveScroll: true });
}
</script>

<template>
    <Head title="უცნობი ბარათები" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">უცნობი ბარათები</h1>
                <p class="text-muted-foreground text-sm">
                    BioStar-იდან მოსული ბარათი, რომელიც CRM-ში ვერცერთ თანამშრომელს ვერ დაუკავშირდა ავტომატურად.
                    დადასტურება ქმნის ბარათს და თანამშრომელს დაუკავშირებულ ისტორიულ დასწრებას ხელახლა ამუშავებს.
                </p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" :checked="includeResolved" class="size-4" @change="toggleResolved" />
                გადაწყვეტილების ჩვენებაც
            </label>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="mappings.data.length === 0"
                title="გადასამოწმებელი ბარათი არ არის"
                description="ყველა შემოსული ბარათი უკვე ცნობილია CRM-სთვის."
            />
            <div v-else class="divide-border divide-y">
                <div v-for="mapping in mappings.data" :key="mapping.id" class="p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ mapping.external_identifier }}</p>
                            <p class="text-muted-foreground truncate text-sm">
                                {{ mapping.source_system }} · {{ mapping.sample_device_serial || 'უცნობი მოწყობილობა' }} ·
                                {{ mapping.event_count }} დაფიქსირება
                                <template v-if="mapping.first_event_at">
                                    · {{ formatDateTime(mapping.first_event_at) }}–{{ formatDateTime(mapping.last_event_at) }}
                                </template>
                            </p>
                        </div>
                        <StatusBadge :label="STATUS_LABEL[mapping.status] || mapping.status" :tone="STATUS_TONE[mapping.status] || 'neutral'" />
                    </div>

                    <p v-if="mapping.confirmed_by" class="text-muted-foreground mt-1 text-xs">
                        {{ mapping.status === 'ignored' ? 'იგნორირებულია' : 'დაადასტურა' }}: {{ mapping.confirmed_by }} ·
                        {{ formatDateTime(mapping.confirmed_at) }}
                        <template v-if="mapping.note"> — {{ mapping.note }}</template>
                    </p>

                    <div v-if="canManage && mapping.status === 'pending'" class="mt-3 flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" @click="openConfirm[mapping.id] = !openConfirm[mapping.id]"
                            >თანამშრომელთან დაკავშირება</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="ignoreFormFor(mapping.id).processing"
                            @click="submitIgnore(mapping.id)"
                            >იგნორირება</Button
                        >
                    </div>

                    <form
                        v-if="openConfirm[mapping.id]"
                        class="border-border mt-3 grid gap-2 rounded-lg border p-3 md:grid-cols-3"
                        @submit.prevent="submitConfirm(mapping.id)"
                    >
                        <select
                            v-model="confirmFormFor(mapping.id).employee_id"
                            required
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="" disabled>აირჩიეთ თანამშრომელი</option>
                            <option v-for="employee in employees" :key="employee.id" :value="employee.id">
                                {{ employee.first_name }} {{ employee.last_name }}
                            </option>
                        </select>
                        <Input
                            v-model="confirmFormFor(mapping.id).valid_from"
                            type="date"
                            placeholder="მოქმედია დან (ცარიელი = პირველი დაფიქსირება)"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            class="w-fit"
                            :disabled="confirmFormFor(mapping.id).processing"
                            >დადასტურება</Button
                        >
                        <p v-if="confirmFormFor(mapping.id).errors.employee_id" class="text-destructive text-sm md:col-span-3">
                            {{ confirmFormFor(mapping.id).errors.employee_id }}
                        </p>
                    </form>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground">გვერდი {{ mappings.current_page }} / {{ mappings.last_page }}</span>
            <div class="flex gap-2">
                <Button v-if="mappings.prev_page_url" as-child variant="outline" size="sm"
                    ><a :href="mappings.prev_page_url!">წინა</a></Button
                >
                <Button v-if="mappings.next_page_url" as-child variant="outline" size="sm"
                    ><a :href="mappings.next_page_url!">შემდეგი</a></Button
                >
            </div>
        </div>
    </div>
</template>
