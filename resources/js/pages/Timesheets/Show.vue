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

type EmailDelivery = {
    id: string;
    recipient_email: string;
    subject: string;
    timesheet_version_at_send: number;
    status: 'queued' | 'sent' | 'failed';
    failed_reason: string | null;
    sent_at: string | null;
    requested_by_name: string | null;
    created_at: string | null;
};

defineOptions({ layout: { mobileTitle: 'ტაბელი' } });

const props = defineProps<{
    timesheet: Timesheet;
    canSubmit: boolean;
    canApprove: boolean;
    canLock: boolean;
    canSend: boolean;
    emailDeliveries: EmailDelivery[];
    defaultRecipientEmail: string | null;
}>();

const submitForm = useForm({ version: props.timesheet.version });
const approveForm = useForm({ version: props.timesheet.version, owner_self_approval_exception_acknowledged: false });
const rejectForm = useForm({ version: props.timesheet.version, reason: '' });
const lockForm = useForm({ version: props.timesheet.version });
const emailForm = useForm({
    recipient_email: props.defaultRecipientEmail ?? '',
    recipient_user_id: null as string | null,
    subject: props.timesheet.pay_period
        ? `თქვენი ტაბელი — ${props.timesheet.pay_period.starts_on} — ${props.timesheet.pay_period.ends_on}`
        : 'თქვენი ტაბელი',
});

function sendEmail() {
    emailForm.post(`/timesheets/${props.timesheet.id}/email`, { preserveScroll: true });
}

function retryEmail(deliveryId: string) {
    useForm({}).post(`/timesheets/${props.timesheet.id}/email/${deliveryId}/retry`, { preserveScroll: true });
}

function emailStatusTone(status: EmailDelivery['status']): 'success' | 'warning' | 'neutral' | 'destructive' {
    if (status === 'sent') return 'success';
    if (status === 'failed') return 'destructive';
    return 'neutral';
}

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

        <div v-if="canSend" class="border-border bg-card flex flex-col gap-4 rounded-xl border p-4">
            <h2 class="font-semibold">იმეილზე გაგზავნა</h2>

            <form class="flex flex-col gap-3" @submit.prevent="sendEmail">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium" for="recipient_email">მიმღების ელფოსტა</label>
                    <input
                        id="recipient_email"
                        v-model="emailForm.recipient_email"
                        type="email"
                        required
                        class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                    <p v-if="emailForm.errors.recipient_email" class="text-destructive text-xs">{{ emailForm.errors.recipient_email }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium" for="subject">თემა</label>
                    <input
                        id="subject"
                        v-model="emailForm.subject"
                        type="text"
                        required
                        class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                    <p v-if="emailForm.errors.subject" class="text-destructive text-xs">{{ emailForm.errors.subject }}</p>
                </div>
                <p class="text-muted-foreground text-xs">
                    დაერთვება ამჟამინდელი ვერსიის (v{{ timesheet.version }}) PDF ასლი — შემდგომი ცვლილებები მასზე არ აისახება.
                </p>
                <Button type="submit" :disabled="emailForm.processing" class="w-fit">გაგზავნა</Button>
            </form>

            <div v-if="emailDeliveries.length" class="flex flex-col gap-2">
                <h3 class="text-muted-foreground text-sm font-medium">გაგზავნის ისტორია</h3>
                <div v-for="delivery in emailDeliveries" :key="delivery.id" class="border-border flex items-center justify-between gap-2 rounded-md border p-2 text-sm">
                    <div>
                        <div class="flex items-center gap-2">
                            <span>{{ delivery.recipient_email }}</span>
                            <StatusBadge :label="delivery.status" :tone="emailStatusTone(delivery.status)" />
                            <span class="text-muted-foreground text-xs">v{{ delivery.timesheet_version_at_send }}</span>
                        </div>
                        <p v-if="delivery.status === 'failed' && delivery.failed_reason" class="text-destructive text-xs">{{ delivery.failed_reason }}</p>
                    </div>
                    <Button v-if="delivery.status === 'failed'" variant="outline" size="sm" @click="retryEmail(delivery.id)">ხელახლა გაგზავნა</Button>
                </div>
            </div>
        </div>
    </div>
</template>
