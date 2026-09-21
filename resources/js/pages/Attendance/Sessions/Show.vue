<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import StatusBadge from '@/components/StatusBadge.vue';

type Session = {
    id: string;
    employee_name: string | null;
    site_name: string | null;
    project_id: string | null;
    project_name: string | null;
    work_date: string;
    clock_in_at: string | null;
    clock_out_at: string | null;
    raw_duration_minutes: number | null;
    payable_minutes: number | null;
    status: string;
};

type Anomaly = {
    id: string;
    anomaly_type: string;
    detected_at: string;
    resolved_at: string | null;
    details: Record<string, unknown>;
};

defineOptions({ layout: { mobileTitle: 'დასწრების სესია' } });

const props = defineProps<{
    session: Session;
    anomalies: Anomaly[];
    canManage: boolean;
}>();

const projectForm = useForm({ project_id: props.session.project_id ?? '' });

function submitProject() {
    projectForm.post(`/attendance/sessions/${props.session.id}/attribute-project`, { preserveScroll: true });
}

function statusTone(status: string): 'success' | 'warning' | 'neutral' {
    if (status === 'closed') return 'success';
    if (status === 'open') return 'warning';
    return 'neutral';
}
</script>

<template>
    <Head title="დასწრების სესია" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/attendance/sessions" class="text-muted-foreground text-sm hover:underline">← სესიები</Link>
            <div class="mt-2 flex items-center gap-3">
                <h1 class="text-2xl font-semibold">{{ session.employee_name }} — {{ session.work_date }}</h1>
                <StatusBadge :label="session.status" :tone="statusTone(session.status)" />
            </div>
        </div>

        <div class="border-border bg-card grid gap-4 rounded-xl border p-5 sm:grid-cols-2">
            <div>
                <p class="text-muted-foreground text-xs">ობიექტი</p>
                <p class="font-medium">{{ session.site_name || '—' }}</p>
            </div>
            <div>
                <p class="text-muted-foreground text-xs">პროექტი</p>
                <p class="font-medium">{{ session.project_name || 'არ არის მიბმული' }}</p>
            </div>
            <div>
                <p class="text-muted-foreground text-xs">შემოსვლა</p>
                <p class="font-medium">{{ session.clock_in_at ?? '—' }}</p>
            </div>
            <div>
                <p class="text-muted-foreground text-xs">გასვლა</p>
                <p class="font-medium">{{ session.clock_out_at ?? 'ღიაა (გასვლის მონაცემი არ არის)' }}</p>
            </div>
            <div>
                <p class="text-muted-foreground text-xs">სრული ხანგრძლივობა</p>
                <p class="font-medium">{{ session.raw_duration_minutes ?? '—' }} წთ</p>
            </div>
            <div>
                <p class="text-muted-foreground text-xs">ანაზღაურებადი დრო</p>
                <p class="font-medium">{{ session.payable_minutes ?? '—' }} წთ</p>
            </div>
        </div>

        <form v-if="canManage" class="border-border bg-card flex flex-wrap items-end gap-3 rounded-xl border p-5" @submit.prevent="submitProject">
            <div class="grid gap-2">
                <label class="text-sm font-medium">პროექტის ID-ის მითითება</label>
                <input v-model="projectForm.project_id" class="border-input bg-background rounded-md border px-3 py-2 text-sm" placeholder="project uuid" required />
            </div>
            <Button type="submit" :disabled="projectForm.processing">მიბმა</Button>
        </form>

        <div>
            <h2 class="mb-2 text-lg font-semibold">ანომალიები</h2>
            <p v-if="anomalies.length === 0" class="text-muted-foreground text-sm">ანომალია არ დაფიქსირებულა.</p>
            <div v-else class="border-border bg-card divide-border divide-y overflow-hidden rounded-xl border">
                <div v-for="anomaly in anomalies" :key="anomaly.id" class="flex items-center justify-between p-3">
                    <div>
                        <p class="font-medium">{{ anomaly.anomaly_type }}</p>
                        <p class="text-muted-foreground text-xs">{{ anomaly.detected_at }}</p>
                    </div>
                    <StatusBadge :label="anomaly.resolved_at ? 'მოგვარებული' : 'აქტიური'" :tone="anomaly.resolved_at ? 'success' : 'warning'" />
                </div>
            </div>
        </div>
    </div>
</template>
