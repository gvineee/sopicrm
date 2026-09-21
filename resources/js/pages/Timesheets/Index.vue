<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Employee = { id: string; first_name: string; last_name: string };
type PayPeriod = { id: string; starts_on: string; ends_on: string };
type Timesheet = {
    id: string;
    employee_name: string | null;
    pay_period: { starts_on: string; ends_on: string } | null;
    status: string;
    lines_sum_payable_minutes: number | null;
};

defineOptions({ layout: { mobileTitle: 'ტაბელები' } });

const props = defineProps<{
    timesheets: { data: Timesheet[]; links: { url: string | null; label: string; active: boolean }[] };
    employees: Employee[];
    payPeriods: PayPeriod[];
    canGenerate: boolean;
    filters: { employee_id: string | null; status: string | null };
}>();

const form = useForm({ employee_id: '', pay_period_id: '' });

function generate() {
    form.post('/timesheets/generate', { preserveScroll: true });
}

function statusTone(status: string): 'success' | 'warning' | 'neutral' | 'destructive' {
    if (status === 'locked' || status === 'approved') return 'success';
    if (status === 'submitted') return 'warning';
    if (status === 'rejected') return 'destructive';
    return 'neutral';
}
</script>

<template>
    <Head title="ტაბელები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">ტაბელები</h1>
            <p class="text-muted-foreground text-sm">დასწრების სესიებიდან გენერირებული საათობრივი/დღიური ტაბელები, დამტკიცების ჯაჭვით.</p>
        </div>

        <form v-if="canGenerate" class="border-border bg-card grid gap-4 rounded-xl border p-5 sm:grid-cols-3" @submit.prevent="generate">
            <div class="grid gap-2">
                <label class="text-sm font-medium">თანამშრომელი</label>
                <select v-model="form.employee_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                    <option value="" disabled>აირჩიეთ</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.first_name }} {{ e.last_name }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <label class="text-sm font-medium">ანაზღაურების პერიოდი</label>
                <select v-model="form.pay_period_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                    <option value="" disabled>აირჩიეთ</option>
                    <option v-for="p in payPeriods" :key="p.id" :value="p.id">{{ p.starts_on }} – {{ p.ends_on }}</option>
                </select>
            </div>
            <div class="flex items-end">
                <Button type="submit" :disabled="form.processing" class="w-full">ტაბელის გენერირება</Button>
            </div>
        </form>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="timesheets.data.length === 0" title="ტაბელი არ მოიძებნა" description="დაგენერირეთ ტაბელი ზემოთ მოცემული ფორმით." />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="timesheet in timesheets.data"
                    :key="timesheet.id"
                    :href="`/timesheets/${timesheet.id}`"
                    class="hover:bg-muted/50 grid gap-3 p-4 md:grid-cols-[1fr_1fr_120px_auto] md:items-center"
                >
                    <p class="truncate font-medium">{{ timesheet.employee_name }}</p>
                    <p class="text-muted-foreground text-sm">
                        {{ timesheet.pay_period?.starts_on }} – {{ timesheet.pay_period?.ends_on }}
                    </p>
                    <p class="text-muted-foreground text-sm">{{ timesheet.lines_sum_payable_minutes ?? 0 }} წთ</p>
                    <StatusBadge :label="timesheet.status" :tone="statusTone(timesheet.status)" />
                </Link>
            </div>
        </div>

        <div v-if="timesheets.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in timesheets.links"
                :key="i"
                :href="link.url ?? ''"
                :class="['rounded px-2 py-1 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground', !link.url && 'pointer-events-none opacity-50']"
                v-html="link.label"
            />
        </div>
    </div>
</template>
