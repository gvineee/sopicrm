<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { payRunStatusLabel } from '@/lib/labels';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type PayPeriod = { id: string; starts_on: string; ends_on: string };
type PayRun = {
    id: string;
    pay_period: PayPeriod | null;
    status: string;
    lines_sum_net_amount: string | null;
};

defineOptions({ layout: { mobileTitle: 'ანგარიშსწორებები' } });

defineProps<{
    payRuns: { data: PayRun[]; links: { url: string | null; label: string; active: boolean }[] };
    payPeriods: PayPeriod[];
    canCreate: boolean;
}>();

const form = useForm({ pay_period_id: '' });

function submit() {
    form.post('/payroll/pay-runs', { preserveScroll: true });
}

function statusTone(status: string): 'success' | 'warning' | 'neutral' {
    if (status === 'locked' || status === 'approved') return 'success';
    if (status === 'draft') return 'neutral';
    return 'warning';
}
</script>

<template>
    <Head title="ანგარიშსწორებები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">ანგარიშსწორებები</h1>
            <p class="text-muted-foreground text-sm">შავი ვარიანტი → გამოთვლილი → გადამოწმებული → დამტკიცებული → ჩაკეტილი.</p>
        </div>

        <form v-if="canCreate" class="border-border bg-card flex flex-wrap items-end gap-3 rounded-xl border p-5" @submit.prevent="submit">
            <div class="grid gap-2">
                <label class="text-sm font-medium">ანაზღაურების პერიოდი</label>
                <select v-model="form.pay_period_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                    <option value="" disabled>აირჩიეთ</option>
                    <option v-for="p in payPeriods" :key="p.id" :value="p.id">{{ p.starts_on }} – {{ p.ends_on }}</option>
                </select>
            </div>
            <Button type="submit" :disabled="form.processing">ანგარიშსწორების შექმნა</Button>
        </form>
        <p v-if="form.errors.pay_period_id" class="text-destructive text-sm">{{ form.errors.pay_period_id }}</p>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="payRuns.data.length === 0" title="ანგარიშსწორება არ არის შექმნილი" description="შექმენით ანგარიშსწორება პერიოდისთვის." />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="payRun in payRuns.data"
                    :key="payRun.id"
                    :href="`/payroll/pay-runs/${payRun.id}`"
                    class="hover:bg-muted/50 grid gap-3 p-4 md:grid-cols-[1fr_140px_auto] md:items-center"
                >
                    <p class="font-medium">{{ payRun.pay_period?.starts_on }} – {{ payRun.pay_period?.ends_on }}</p>
                    <p class="text-muted-foreground text-sm">{{ payRun.lines_sum_net_amount ?? '0.00' }} GEL</p>
                    <StatusBadge :label="payRunStatusLabel(payRun.status)" :tone="statusTone(payRun.status)" />
                </Link>
            </div>
        </div>
    </div>
</template>
