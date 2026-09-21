<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import StatusBadge from '@/components/StatusBadge.vue';

type Line = {
    id: string;
    work_date: string;
    project_name: string | null;
    payable_minutes: number;
    rate_type: string;
};

type Timesheet = {
    id: string;
    employee_name: string | null;
    pay_period: { starts_on: string; ends_on: string } | null;
    status: string;
    version: number;
    rejected_reason: string | null;
    lines: Line[];
};

defineOptions({ layout: { mobileTitle: 'ტაბელი' } });

const props = defineProps<{
    timesheet: Timesheet;
    canSubmit: boolean;
    canApprove: boolean;
    canLock: boolean;
}>();

const submitForm = useForm({ version: props.timesheet.version });
const approveForm = useForm({ version: props.timesheet.version, owner_self_approval_exception_acknowledged: false });
const rejectForm = useForm({ version: props.timesheet.version, reason: '' });
const lockForm = useForm({ version: props.timesheet.version });

function submit() {
    submitForm.post(`/timesheets/${props.timesheet.id}/submit`, { preserveScroll: true });
}
function approve() {
    approveForm.post(`/timesheets/${props.timesheet.id}/approve`, { preserveScroll: true });
}
function reject() {
    const reason = prompt('უარყოფის მიზეზი:');
    if (!reason) return;
    rejectForm.transform((d) => ({ ...d, reason })).post(`/timesheets/${props.timesheet.id}/reject`, { preserveScroll: true });
}
function lock() {
    lockForm.post(`/timesheets/${props.timesheet.id}/lock`, { preserveScroll: true });
}

function statusTone(status: string): 'success' | 'warning' | 'neutral' | 'destructive' {
    if (status === 'locked' || status === 'approved') return 'success';
    if (status === 'submitted') return 'warning';
    if (status === 'rejected') return 'destructive';
    return 'neutral';
}
</script>

<template>
    <Head title="ტაბელი" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/timesheets" class="text-muted-foreground text-sm hover:underline">← ტაბელები</Link>
            <div class="mt-2 flex items-center gap-3">
                <h1 class="text-2xl font-semibold">{{ timesheet.employee_name }}</h1>
                <StatusBadge :label="timesheet.status" :tone="statusTone(timesheet.status)" />
            </div>
            <p class="text-muted-foreground text-sm">{{ timesheet.pay_period?.starts_on }} – {{ timesheet.pay_period?.ends_on }}</p>
            <p v-if="timesheet.rejected_reason" class="text-destructive mt-1 text-sm">უარყოფის მიზეზი: {{ timesheet.rejected_reason }}</p>
            <a
                :href="`/timesheets/${timesheet.id}/pdf`"
                target="_blank"
                rel="noopener"
                class="text-primary mt-2 inline-block text-sm hover:underline"
            >
                PDF ნახვა (ვერსია {{ timesheet.version }})
            </a>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="p-3">თარიღი</th>
                        <th class="p-3">პროექტი</th>
                        <th class="p-3">წუთები</th>
                        <th class="p-3">ტიპი</th>
                    </tr>
                </thead>
                <tbody class="divide-border divide-y">
                    <tr v-for="line in timesheet.lines" :key="line.id">
                        <td class="p-3">{{ line.work_date }}</td>
                        <td class="p-3">{{ line.project_name }}</td>
                        <td class="p-3">{{ line.payable_minutes }}</td>
                        <td class="p-3">{{ line.rate_type }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button v-if="canSubmit && timesheet.status === 'draft'" :disabled="submitForm.processing" @click="submit">გაგზავნა განსახილველად</Button>
            <Button v-if="canApprove && timesheet.status === 'submitted'" :disabled="approveForm.processing" @click="approve">დამტკიცება</Button>
            <Button v-if="canApprove && timesheet.status === 'submitted'" variant="outline" :disabled="rejectForm.processing" @click="reject">უარყოფა</Button>
            <Button v-if="canLock && timesheet.status === 'approved'" :disabled="lockForm.processing" @click="lock">დახურვა</Button>
        </div>
    </div>
</template>
