<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import StatusBadge from '@/components/StatusBadge.vue';

type Line = {
    id: string;
    employee_name: string | null;
    project_name: string | null;
    basis: string;
    quantity: string;
    formula_applied: string;
    net_amount: string;
    exceeds_daily_cap: boolean;
};

type PayRun = {
    id: string;
    pay_period: { starts_on: string; ends_on: string } | null;
    status: string;
    version: number;
    lines: Line[];
    total_net_amount: string | null;
};

defineOptions({ layout: { mobileTitle: 'ანგარიშსწორება' } });

const props = defineProps<{
    payRun: PayRun;
    canCalculate: boolean;
    canReview: boolean;
    canApprove: boolean;
    canLock: boolean;
}>();

function act(action: string) {
    useForm({ version: props.payRun.version }).post(`/payroll/pay-runs/${props.payRun.id}/${action}`, { preserveScroll: true });
}

function statusTone(status: string): 'success' | 'warning' | 'neutral' {
    if (status === 'locked' || status === 'approved') return 'success';
    if (status === 'draft') return 'neutral';
    return 'warning';
}
</script>

<template>
    <Head title="ანგარიშსწორება" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/payroll/pay-runs" class="text-muted-foreground text-sm hover:underline">← ანგარიშსწორებები</Link>
            <div class="mt-2 flex items-center gap-3">
                <h1 class="text-2xl font-semibold">{{ payRun.pay_period?.starts_on }} – {{ payRun.pay_period?.ends_on }}</h1>
                <StatusBadge :label="payRun.status" :tone="statusTone(payRun.status)" />
            </div>
            <p class="text-muted-foreground text-sm">სულ: {{ payRun.total_net_amount ?? '0.00' }} GEL</p>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="p-3">თანამშრომელი</th>
                        <th class="p-3">პროექტი</th>
                        <th class="p-3">ფორმულა</th>
                        <th class="p-3">თანხა</th>
                    </tr>
                </thead>
                <tbody class="divide-border divide-y">
                    <tr v-for="line in payRun.lines" :key="line.id">
                        <td class="p-3">{{ line.employee_name }}</td>
                        <td class="p-3">{{ line.project_name || '—' }}</td>
                        <td class="p-3 text-xs">{{ line.formula_applied }}</td>
                        <td class="p-3">
                            {{ line.net_amount }} GEL
                            <span v-if="line.exceeds_daily_cap" class="text-warning ml-1 text-xs">(ლიმიტი გადაცილებული)</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button v-if="canCalculate && ['draft', 'calculated'].includes(payRun.status)" @click="act('calculate')">გამოთვლა</Button>
            <Button v-if="canReview && payRun.status === 'calculated'" @click="act('review')">გადახედვა</Button>
            <Button v-if="canApprove && payRun.status === 'reviewed'" @click="act('approve')">დამტკიცება</Button>
            <Button v-if="canLock && payRun.status === 'approved'" @click="act('lock')">დახურვა</Button>
        </div>
    </div>
</template>
