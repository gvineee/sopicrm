<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Employee = { id: string; first_name: string; last_name: string };
type Session = {
    id: string;
    employee_name: string | null;
    site_name: string | null;
    project_name: string | null;
    work_date: string;
    clock_in_at: string | null;
    clock_out_at: string | null;
    payable_minutes: number | null;
    status: string;
    anomalies_count: number | null;
};

defineOptions({ layout: { mobileTitle: 'დასწრების სესიები' } });

const props = defineProps<{
    sessions: { data: Session[]; links: { url: string | null; label: string; active: boolean }[] };
    employees: Employee[];
    canReconstruct: boolean;
    filters: { employee_id: string | null };
}>();

const reconstructForm = useForm({
    employee_id: props.filters.employee_id ?? '',
    from: new Date(new Date().setDate(1)).toISOString().slice(0, 10),
    to: new Date().toISOString().slice(0, 10),
});

function reconstruct() {
    reconstructForm.post('/attendance/sessions/reconstruct', { preserveScroll: true });
}

function filterByEmployee(employeeId: string) {
    router.get('/attendance/sessions', { employee_id: employeeId || undefined }, { preserveScroll: true, preserveState: true });
}

function statusTone(status: string): 'success' | 'warning' | 'neutral' {
    if (status === 'closed') return 'success';
    if (status === 'open') return 'warning';
    return 'neutral';
}
</script>

<template>
    <Head title="დასწრების სესიები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">დასწრების სესიები</h1>
            <p class="text-muted-foreground text-sm">RawAccessEvent-ებიდან აღდგენილი შემოსვლა/გასვლის სესიები.</p>
        </div>

        <form v-if="canReconstruct" class="border-border bg-card grid gap-4 rounded-xl border p-5 sm:grid-cols-4" @submit.prevent="reconstruct">
            <div class="grid gap-2">
                <Label for="rec-employee">თანამშრომელი</Label>
                <select id="rec-employee" v-model="reconstructForm.employee_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                    <option value="" disabled>აირჩიეთ</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.first_name }} {{ e.last_name }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="rec-from">დან</Label>
                <Input id="rec-from" v-model="reconstructForm.from" type="date" required />
            </div>
            <div class="grid gap-2">
                <Label for="rec-to">მდე</Label>
                <Input id="rec-to" v-model="reconstructForm.to" type="date" required />
            </div>
            <div class="flex items-end">
                <Button type="submit" :disabled="reconstructForm.processing" class="w-full">სესიების აღდგენა</Button>
            </div>
        </form>

        <div class="flex items-center gap-2">
            <Label for="filter-employee" class="text-sm">ფილტრი:</Label>
            <select
                id="filter-employee"
                :value="filters.employee_id ?? ''"
                class="border-input bg-background rounded-md border px-3 py-1.5 text-sm"
                @change="filterByEmployee(($event.target as HTMLSelectElement).value)"
            >
                <option value="">ყველა თანამშრომელი</option>
                <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.first_name }} {{ e.last_name }}</option>
            </select>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="sessions.data.length === 0" title="სესია არ მოიძებნა" description="აღადგინეთ სესიები ზემოთ მოცემული ფორმით." />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="session in sessions.data"
                    :key="session.id"
                    :href="`/attendance/sessions/${session.id}`"
                    class="hover:bg-muted/50 grid gap-3 p-4 md:grid-cols-[140px_1fr_1fr_120px_100px_auto] md:items-center"
                >
                    <p class="text-sm">{{ session.work_date }}</p>
                    <p class="truncate font-medium">{{ session.employee_name }}</p>
                    <p class="text-muted-foreground truncate text-sm">{{ session.project_name || session.site_name || '—' }}</p>
                    <p class="text-muted-foreground text-sm">{{ session.payable_minutes ?? '—' }} წთ</p>
                    <StatusBadge :label="session.status" :tone="statusTone(session.status)" />
                    <p v-if="session.anomalies_count" class="text-destructive text-sm">{{ session.anomalies_count }} ანომალია</p>
                </Link>
            </div>
        </div>

        <div v-if="sessions.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in sessions.links"
                :key="i"
                :href="link.url ?? ''"
                :class="['rounded px-2 py-1 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground', !link.url && 'pointer-events-none opacity-50']"
                v-html="link.label"
            />
        </div>
    </div>
</template>
