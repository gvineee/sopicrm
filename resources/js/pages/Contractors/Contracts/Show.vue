<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type ContractStatus = 'draft' | 'pending_approval' | 'active' | 'closed';

type Contract = {
    id: string;
    contractor_id: string;
    project: { id: string; name: string } | null;
    title: string;
    rate_type: string;
    rate_amount: string | null;
    total_amount: string | null;
    currency: string;
    starts_on: string | null;
    ends_on: string | null;
    status: ContractStatus;
    rejection_reason: string | null;
    outstanding_balance: number | null;
    terms: string | null;
};

type Act = {
    id: string;
    project_id: string;
    description: string | null;
    quantity: string | null;
    evidence: Array<{ id: string; original_filename: string; mime_type: string | null; status: string; url: string | null }>;
    submitted_at: string | null;
    status: 'pending_review' | 'accepted' | 'returned';
    returned_reason: string | null;
    acceptance: { accepted_quantity: string | null; accepted_amount: string; notes: string | null } | null;
    can: { accept: boolean; return: boolean };
};

type Payment = {
    id: string;
    amount: string;
    currency: string;
    paid_at: string | null;
    method: string | null;
    reference: string | null;
};

const props = defineProps<{
    contractor: { id: string; name: string };
    contract: Contract;
    acts: Act[];
    payments: Payment[];
    pendingEvidence: Array<{ id: string; original_filename: string; caption: string | null }>;
    projects: Array<{ id: string; name: string }>;
    canManage: boolean;
    canApprove: boolean;
    canSubmitAct: boolean;
    canRecordPayment: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'კონტრაქტი' } });

const STATUS_LABEL: Record<ContractStatus, string> = {
    draft: 'დრაფტი',
    pending_approval: 'დასამტკიცებელი',
    active: 'აქტიური',
    closed: 'დახურული',
};
const STATUS_TONE: Record<ContractStatus, 'neutral' | 'warning' | 'success'> = {
    draft: 'neutral',
    pending_approval: 'warning',
    active: 'success',
    closed: 'neutral',
};

const rejectForm = useForm({ reason: '' });
const showRejectForm = ref(false);

function submitForApproval() {
    useForm({}).post(`/contractors/${props.contractor.id}/contracts/${props.contract.id}/submit-for-approval`, { preserveScroll: true });
}
function approve() {
    useForm({}).post(`/contractors/${props.contractor.id}/contracts/${props.contract.id}/approve`, { preserveScroll: true });
}
function reject() {
    rejectForm.post(`/contractors/${props.contractor.id}/contracts/${props.contract.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => { rejectForm.reset(); showRejectForm.value = false; },
    });
}
function close() {
    if (!confirm('ნამდვილად გსურთ კონტრაქტის დახურვა?')) return;
    useForm({}).post(`/contractors/${props.contractor.id}/contracts/${props.contract.id}/close`, { preserveScroll: true });
}

const uploadForm = useForm<{ file: File | null; caption: string }>({ file: null, caption: '' });
function handleFile(event: Event) {
    uploadForm.file = (event.target as HTMLInputElement).files?.[0] || null;
}
function uploadEvidence() {
    uploadForm.post(`/contractors/${props.contractor.id}/attachments`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    });
}

const actForm = useForm<{ project_id: string; description: string; quantity: string; attachment_ids: string[] }>({
    project_id: props.contract.project?.id ?? '',
    description: '',
    quantity: '',
    attachment_ids: [],
});
function submitAct() {
    useForm({
        contract_id: props.contract.id,
        project_id: actForm.project_id,
        description: actForm.description,
        quantity: actForm.quantity,
        attachment_ids: actForm.attachment_ids,
    }).post(`/contractors/${props.contractor.id}/acts`, {
        preserveScroll: true,
        onSuccess: () => actForm.reset('description', 'quantity', 'attachment_ids'),
    });
}

const acceptForms = ref<Record<string, { accepted_quantity: string; accepted_amount: string; notes: string }>>({});
function acceptFormFor(actId: string) {
    if (!acceptForms.value[actId]) {
        acceptForms.value[actId] = { accepted_quantity: '', accepted_amount: '', notes: '' };
    }
    return acceptForms.value[actId];
}
function acceptAct(actId: string) {
    const data = acceptFormFor(actId);
    useForm(data).post(`/contractors/${props.contractor.id}/acts/${actId}/accept`, { preserveScroll: true });
}

const returnReasons = ref<Record<string, string>>({});
function returnAct(actId: string) {
    const reason = returnReasons.value[actId];
    if (!reason) return;
    useForm({ reason }).post(`/contractors/${props.contractor.id}/acts/${actId}/return`, { preserveScroll: true });
}

const paymentForm = useForm({ amount: '', currency: props.contract.currency, paid_at: '', method: '', reference: '', notes: '' });
function recordPayment() {
    paymentForm.post(`/contractors/${props.contractor.id}/contracts/${props.contract.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => paymentForm.reset('amount', 'method', 'reference', 'notes'),
    });
}

const ACT_STATUS_LABEL: Record<Act['status'], string> = { pending_review: 'განსახილველი', accepted: 'მიღებული', returned: 'დაბრუნებული' };
const ACT_STATUS_TONE: Record<Act['status'], 'warning' | 'success' | 'destructive'> = { pending_review: 'warning', accepted: 'success', returned: 'destructive' };
</script>

<template>
    <Head :title="contract.title" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link :href="`/contractors/${contractor.id}`" class="text-muted-foreground text-sm hover:underline">← {{ contractor.name }}</Link>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ contract.title }}</h1>
                    <StatusBadge :label="STATUS_LABEL[contract.status]" :tone="STATUS_TONE[contract.status]" />
                </div>
                <p class="text-muted-foreground text-sm">
                    {{ contract.project?.name || 'ყველა პროექტისთვის' }} · {{ contract.rate_type }} · {{ contract.currency }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="canManage && contract.status === 'draft'" @click="submitForApproval">დასამტკიცებლად გაგზავნა</Button>
                <Button v-if="canApprove && contract.status === 'pending_approval'" @click="approve">დამტკიცება</Button>
                <Button v-if="canApprove && contract.status === 'pending_approval'" variant="outline" @click="showRejectForm = !showRejectForm">უარყოფა</Button>
                <Button v-if="canManage && contract.status === 'active'" variant="outline" @click="close">დახურვა</Button>
            </div>
        </div>

        <p v-if="contract.rejection_reason" class="border-destructive/30 bg-destructive/5 text-destructive rounded-lg border p-3 text-sm">
            უარყოფის მიზეზი: {{ contract.rejection_reason }}
        </p>

        <form v-if="showRejectForm" class="border-border bg-card grid gap-3 rounded-xl border p-4" @submit.prevent="reject">
            <Label for="reject-reason">უარყოფის მიზეზი</Label>
            <Input id="reject-reason" v-model="rejectForm.reason" required maxlength="1000" />
            <Button type="submit" class="w-fit" variant="destructive" :disabled="rejectForm.processing">უარყოფის დადასტურება</Button>
        </form>

        <div v-if="contract.outstanding_balance !== null" class="border-border bg-card rounded-xl border p-5">
            <p class="text-muted-foreground text-sm">გადასახდელი ნაშთი</p>
            <p class="text-2xl font-semibold">{{ contract.outstanding_balance }} {{ contract.currency }}</p>
        </div>

        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">აქტები</h2>
            <EmptyState v-if="acts.length === 0" class="mt-4" title="აქტი არ არსებობს" description="დაამატეთ პირველი აქტი ქვემოთ." />
            <div v-else class="divide-border mt-4 divide-y">
                <div v-for="act in acts" :key="act.id" class="flex flex-col gap-3 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-medium">{{ act.description || 'აღწერის გარეშე' }}</p>
                            <p class="text-muted-foreground text-sm">
                                მოცულობა: {{ act.quantity ?? '—' }} · მტკიცებულება: {{ act.evidence.length }}
                            </p>
                        </div>
                        <StatusBadge :label="ACT_STATUS_LABEL[act.status]" :tone="ACT_STATUS_TONE[act.status]" />
                    </div>

                    <div v-if="act.evidence.length" class="grid gap-2 sm:grid-cols-2">
                        <a
                            v-for="file in act.evidence"
                            :key="file.id"
                            :href="file.url ?? undefined"
                            target="_blank"
                            rel="noopener"
                            class="border-border hover:bg-accent flex items-center gap-3 rounded-lg border p-2 text-sm"
                            :class="!file.url && 'pointer-events-none opacity-60'"
                        >
                            <img
                                v-if="file.url && file.mime_type?.startsWith('image/')"
                                :src="file.url"
                                :alt="file.original_filename"
                                class="size-10 shrink-0 rounded object-cover"
                            />
                            <span class="min-w-0 flex-1 truncate">{{ file.original_filename }}</span>
                        </a>
                    </div>

                    <p v-if="act.acceptance" class="text-muted-foreground text-sm">
                        მიღებულია: {{ act.acceptance.accepted_amount }} {{ contract.currency }}
                    </p>
                    <p v-if="act.returned_reason" class="text-destructive text-sm">დაბრუნების მიზეზი: {{ act.returned_reason }}</p>

                    <div v-if="act.can.accept" class="bg-muted/40 grid gap-2 rounded-lg p-3 sm:grid-cols-4 sm:items-end">
                        <div class="grid gap-1">
                            <Label>მიღებული მოცულობა</Label>
                            <Input v-model="acceptFormFor(act.id).accepted_quantity" type="number" step="0.01" min="0" />
                        </div>
                        <div class="grid gap-1">
                            <Label>მიღებული თანხა</Label>
                            <Input v-model="acceptFormFor(act.id).accepted_amount" type="number" step="0.01" min="0.01" required />
                        </div>
                        <div class="grid gap-1 sm:col-span-1">
                            <Label>შენიშვნა</Label>
                            <Input v-model="acceptFormFor(act.id).notes" />
                        </div>
                        <Button size="sm" @click="acceptAct(act.id)">მიღება</Button>
                    </div>

                    <div v-if="act.can.return" class="flex flex-wrap items-end gap-2">
                        <div class="grid flex-1 gap-1">
                            <Label>დაბრუნების მიზეზი</Label>
                            <Input v-model="returnReasons[act.id]" />
                        </div>
                        <Button size="sm" variant="outline" @click="returnAct(act.id)">დაბრუნება</Button>
                    </div>
                </div>
            </div>

            <div v-if="canSubmitAct && contract.status === 'active'" class="border-border mt-5 grid gap-3 border-t pt-5">
                <h3 class="text-sm font-medium">მტკიცებულების ატვირთვა</h3>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="uploadEvidence">
                    <input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" @change="handleFile" />
                    <Input v-model="uploadForm.caption" placeholder="წარწერა (არასავალდებულო)" class="max-w-xs" />
                    <Button type="submit" size="sm" :disabled="uploadForm.processing || !uploadForm.file">ატვირთვა</Button>
                </form>

                <h3 class="mt-2 text-sm font-medium">ახალი აქტი</h3>
                <form class="grid gap-3" @submit.prevent="submitAct">
                    <div v-if="!contract.project" class="grid gap-2">
                        <Label for="act-project">პროექტი</Label>
                        <select id="act-project" v-model="actForm.project_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                            <option value="" disabled>აირჩიეთ პროექტი</option>
                            <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="act-description">აღწერა</Label>
                        <textarea id="act-description" v-model="actForm.description" rows="2" class="border-input bg-background rounded-md border px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="grid gap-2 sm:w-48">
                        <Label for="act-quantity">მოცულობა</Label>
                        <Input id="act-quantity" v-model="actForm.quantity" type="number" step="0.01" min="0" />
                    </div>
                    <div v-if="pendingEvidence.length > 0" class="grid gap-2">
                        <Label>მტკიცებულება</Label>
                        <label v-for="file in pendingEvidence" :key="file.id" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" :value="file.id" v-model="actForm.attachment_ids" />
                            {{ file.caption || file.original_filename }}
                        </label>
                    </div>
                    <Button type="submit" class="w-fit" :disabled="actForm.processing">აქტის გაგზავნა</Button>
                </form>
            </div>
        </section>

        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">გადახდები</h2>
            <EmptyState v-if="payments.length === 0" class="mt-4" title="გადახდა არ დაფიქსირებულა" />
            <div v-else class="divide-border mt-4 divide-y">
                <div v-for="payment in payments" :key="payment.id" class="flex items-center justify-between py-3 text-sm">
                    <div>
                        <p class="font-medium">{{ payment.amount }} {{ payment.currency }}</p>
                        <p class="text-muted-foreground">{{ payment.paid_at }} · {{ payment.method || 'მეთოდის გარეშე' }}</p>
                    </div>
                    <p class="text-muted-foreground">{{ payment.reference || '' }}</p>
                </div>
            </div>

            <form v-if="canRecordPayment && contract.status === 'active'" class="border-border mt-5 grid gap-3 border-t pt-5" @submit.prevent="recordPayment">
                <h3 class="text-sm font-medium">გადახდის დაფიქსირება</h3>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="payment-amount">თანხა</Label>
                        <Input id="payment-amount" v-model="paymentForm.amount" type="number" step="0.01" min="0.01" required />
                        <p v-if="paymentForm.errors.amount" class="text-destructive text-sm">{{ paymentForm.errors.amount }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="payment-currency">ვალუტა</Label>
                        <Input id="payment-currency" v-model="paymentForm.currency" required maxlength="3" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="payment-date">თარიღი</Label>
                        <Input id="payment-date" v-model="paymentForm.paid_at" type="date" required />
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="payment-method">მეთოდი</Label>
                        <Input id="payment-method" v-model="paymentForm.method" maxlength="64" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="payment-reference">რეფერენსი</Label>
                        <Input id="payment-reference" v-model="paymentForm.reference" maxlength="255" />
                    </div>
                </div>
                <Button type="submit" class="w-fit" :disabled="paymentForm.processing">გადახდის დაფიქსირება</Button>
            </form>
        </section>
    </div>
</template>
