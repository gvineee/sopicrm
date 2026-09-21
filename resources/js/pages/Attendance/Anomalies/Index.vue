<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';

type Anomaly = {
    id: string;
    employee_name: string | null;
    anomaly_type: string;
    detected_at: string;
    resolved_at: string | null;
    details: Record<string, unknown>;
};

defineOptions({ layout: { mobileTitle: 'დასწრების ანომალიები' } });

const props = defineProps<{
    anomalies: { data: Anomaly[]; links: { url: string | null; label: string; active: boolean }[] };
    includeResolved: boolean;
}>();

function toggleResolved() {
    router.get('/attendance/anomalies', { include_resolved: props.includeResolved ? undefined : '1' }, { preserveScroll: true });
}

function resolve(anomaly: Anomaly) {
    const note = prompt('მოგვარების შენიშვნა:');
    if (!note) return;
    useForm({ resolution_note: note }).post(`/attendance/anomalies/${anomaly.id}/resolve`, { preserveScroll: true });
}
</script>

<template>
    <Head title="დასწრების ანომალიები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">დასწრების ანომალიები</h1>
                <p class="text-muted-foreground text-sm">გამორჩენილი გასვლა, დუბლირებული შესვლა, ჯვარედინი ობიექტი და სხვა გამონაკლისები.</p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" :checked="includeResolved" class="size-4" @change="toggleResolved" />
                მოგვარებულების ჩვენებაც
            </label>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="anomalies.data.length === 0" title="აქტიური ანომალია არ არის" description="ყველა დასწრების მონაცემი წესრიგშია." />
            <div v-else class="divide-border divide-y">
                <div v-for="anomaly in anomalies.data" :key="anomaly.id" class="grid gap-3 p-4 md:grid-cols-[1fr_1fr_160px_auto] md:items-center">
                    <p class="font-medium">{{ anomaly.anomaly_type }}</p>
                    <p class="text-muted-foreground truncate text-sm">{{ anomaly.employee_name || 'მოწყობილობის დონე' }}</p>
                    <p class="text-muted-foreground text-sm">{{ anomaly.detected_at }}</p>
                    <div class="flex items-center gap-3">
                        <StatusBadge :label="anomaly.resolved_at ? 'მოგვარებული' : 'აქტიური'" :tone="anomaly.resolved_at ? 'success' : 'warning'" />
                        <button v-if="!anomaly.resolved_at" type="button" class="text-sm hover:underline" @click="resolve(anomaly)">მოგვარება</button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="anomalies.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in anomalies.links"
                :key="i"
                :href="link.url ?? ''"
                :class="['rounded px-2 py-1 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground', !link.url && 'pointer-events-none opacity-50']"
                v-html="link.label"
            />
        </div>
    </div>
</template>
