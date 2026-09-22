<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/components/states/EmptyState.vue';

type Project = { id: string; name: string };

type Row = Record<string, string | number | boolean | null>;

const props = defineProps<{
    report: string;
    projects: Project[];
    filters: { project_id: string | null };
    rows: Row[];
}>();

defineOptions({ layout: { mobileTitle: 'რეპორტები' } });

const reportTabs: { key: string; label: string }[] = [
    { key: 'who-holds-what', label: 'ვის რა უკავია' },
    { key: 'overdue-returns', label: 'ვადაგადაცილებული დაბრუნებები' },
    { key: 'allocation', label: 'პროექტების მიხედვით' },
    { key: 'service-history', label: 'მომსახურების ისტორია' },
    { key: 'lost-assets', label: 'დაკარგული აქტივები' },
];

const causeLabel: Record<string, string> = {
    stocktake_variance: 'ინვენტარიზაციის ვარიაცია',
    incident_write_off: 'ინციდენტის ჩამოწერა',
    unknown: 'უცნობი',
};

const statusLabel: Record<string, string> = {
    scheduled: 'დაგეგმილი',
    completed: 'დასრულებული',
    issued: 'გაცემული',
    awaiting_receipt: 'მიღების მოლოდინში',
};

function switchReport(key: string) {
    router.get('/assets/reports', { report: key, project_id: props.filters.project_id || undefined }, { preserveState: true });
}

function applyProjectFilter(projectId: string) {
    router.get('/assets/reports', { report: props.report, project_id: projectId || undefined }, { preserveState: true });
}

const columns = computed<{ key: string; label: string }[]>(() => {
    switch (props.report) {
        case 'who-holds-what':
            return [
                { key: 'asset_name', label: 'აქტივი' },
                { key: 'inventory_code', label: 'კოდი' },
                { key: 'holder_name', label: 'მფლობელი' },
                { key: 'status', label: 'სტატუსი' },
                { key: 'occurred_at', label: 'გაცემის თარიღი' },
            ];
        case 'overdue-returns':
            return [
                { key: 'asset_name', label: 'აქტივი' },
                { key: 'holder_name', label: 'მფლობელი' },
                { key: 'expected_return_at', label: 'მოსალოდნელი დაბრუნება' },
                { key: 'days_overdue', label: 'ვადაგადაცილება (დღე)' },
            ];
        case 'allocation':
            return [
                { key: 'project_name', label: 'პროექტი' },
                { key: 'asset_name', label: 'აქტივი' },
                { key: 'holder_name', label: 'მფლობელი' },
                { key: 'quantity', label: 'რაოდენობა' },
            ];
        case 'service-history':
            return [
                { key: 'asset_name', label: 'აქტივი' },
                { key: 'vendor', label: 'მომსახურე' },
                { key: 'scheduled_at', label: 'დაგეგმილი' },
                { key: 'completed_at', label: 'დასრულებული' },
                { key: 'actual_cost', label: 'ღირებულება' },
                { key: 'status', label: 'სტატუსი' },
            ];
        case 'lost-assets':
            return [
                { key: 'asset_name', label: 'აქტივი' },
                { key: 'inventory_code', label: 'კოდი' },
                { key: 'cause', label: 'მიზეზი' },
                { key: 'caused_at', label: 'თარიღი' },
            ];
        default:
            return [];
    }
});

function displayValue(row: Row, key: string): string {
    const value = row[key];

    if (value === null || value === undefined) return '—';
    if (key === 'status') return statusLabel[String(value)] ?? String(value);
    if (key === 'cause') return causeLabel[String(value)] ?? String(value);
    if (key.endsWith('_at') && typeof value === 'string') return new Date(value).toLocaleString('ka-GE');
    if (key === 'actual_cost' && typeof value === 'number') return value.toFixed(2);

    return String(value);
}
</script>

<template>
    <Head title="აქტივების რეპორტები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">აქტივების რეპორტები</h1>
            <p class="text-muted-foreground text-sm">ვის რა უკავია, ვადაგადაცილებული დაბრუნებები, პროექტების მიხედვით განაწილება, მომსახურების ისტორია, დაკარგული აქტივები.</p>
        </div>

        <div class="flex flex-wrap gap-2 border-b pb-2">
            <button
                v-for="tab in reportTabs"
                :key="tab.key"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm"
                :class="report === tab.key ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                @click="switchReport(tab.key)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div v-if="report === 'allocation'" class="flex items-center gap-2">
            <label class="text-sm">პროექტი:</label>
            <select
                class="border-input rounded-md border px-2 py-1 text-sm"
                :value="filters.project_id ?? ''"
                @change="applyProjectFilter(($event.target as HTMLSelectElement).value)"
            >
                <option value="">ყველა პროექტი</option>
                <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
            </select>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="rows.length === 0" title="მონაცემი არ არის" description="ამ რეპორტისთვის ჯერ არაფერია საჩვენებელი." />
            <table v-else class="w-full text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th v-for="col in columns" :key="col.key" class="px-4 py-2 font-medium">{{ col.label }}</th>
                    </tr>
                </thead>
                <tbody class="divide-border divide-y">
                    <tr v-for="(row, index) in rows" :key="row.asset_id ? `${row.asset_id}-${index}` : index">
                        <td v-for="col in columns" :key="col.key" class="px-4 py-2">
                            <Link v-if="col.key === 'asset_name' && row.asset_id" :href="`/assets/${row.asset_id}`" class="hover:underline">
                                {{ displayValue(row, col.key) }}
                            </Link>
                            <span v-else>{{ displayValue(row, col.key) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
