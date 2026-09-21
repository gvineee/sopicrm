<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Employee = { id: string; first_name: string; last_name: string };
type CustodyLine = { id: string; asset_id: string; asset_name: string | null; inventory_code: string | null; quantity: number; returned_quantity: number; outstanding: number; line_condition: string | null };
type CustodyTransaction = {
    id: string;
    type: string;
    status: string;
    version: number;
    receiving_employee_id: string | null;
    receiving_employee_name: string | null;
    occurred_at: string | null;
    expected_return_at: string | null;
    return_requested_at: string | null;
    received_confirmation_at: string | null;
    lines: CustodyLine[];
};
type Incident = {
    id: string;
    asset_id: string;
    incident_type: string;
    occurred_at: string | null;
    description: string;
    estimated_repair_cost: number | null;
    reported_by_name: string | null;
    decision: string | null;
    decided_at: string | null;
    version: number;
};
type Asset = {
    id: string;
    name: string;
    category: string;
    tracking_type: string;
    inventory_code: string;
    condition: string;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    quantity_on_hand: number | null;
    active_custody_status: string | null;
};

const props = defineProps<{
    asset: Asset;
    activeTransaction: CustodyTransaction | null;
    custodyHistory: CustodyTransaction[];
    incidents: Incident[];
    employees: Employee[];
    can: { manageCustody: boolean; reportIncident: boolean; decideIncident: boolean };
}>();

defineOptions({ layout: { mobileTitle: 'აქტივი' } });

const conditionLabel: Record<string, string> = {
    new: 'ახალი', good: 'კარგი', fair: 'დამაკმაყოფილებელი', damaged: 'დაზიანებული', under_repair: 'შეკეთებაშია', written_off: 'ჩამოწერილი',
};
const statusLabel: Record<string, string> = {
    draft: 'მონახაზი', awaiting_receipt: 'მიღების მოლოდინში', issued: 'გაცემული',
    partially_returned: 'ნაწილობრივ დაბრუნებული', returned: 'დაბრუნებული', in_transit: 'გადაცემის პროცესში',
};
const incidentTypeLabel: Record<string, string> = { damage: 'დაზიანება', loss: 'დაკარგვა', write_off_request: 'ჩამოწერის მოთხოვნა' };
const decisionLabel: Record<string, string> = { repair: 'შეკეთება', write_off: 'ჩამოწერა', no_action: 'ქმედების გარეშე' };

const showIssueForm = ref(false);
const showTransferForm = ref(false);
const showReturnForm = ref(false);
const showIncidentForm = ref(false);

const issueForm = useForm({
    receiving_employee_id: '',
    condition_at_transaction: 'good',
    expected_return_at: '',
    comment: '',
});
function submitIssue() {
    issueForm.post(`/assets/${props.asset.id}/issue`, { preserveScroll: true, onSuccess: () => (showIssueForm.value = false) });
}

const transferForm = useForm({
    receiving_employee_id: '',
    condition_at_transaction: 'good',
    comment: '',
});
function submitTransfer() {
    transferForm.post(`/assets/${props.asset.id}/transfer`, { preserveScroll: true, onSuccess: () => (showTransferForm.value = false) });
}

const returnForm = useForm({
    comment: '',
    lines: [] as { custody_line_id: string; quantity: number; condition: string }[],
});
function openReturnForm() {
    if (!props.activeTransaction) return;
    returnForm.lines = props.activeTransaction.lines
        .filter((line) => line.outstanding > 0)
        .map((line) => ({ custody_line_id: line.id, quantity: line.outstanding, condition: 'good' }));
    showReturnForm.value = true;
}
function submitReturn() {
    if (!props.activeTransaction) return;
    returnForm.post(`/assets/custody/${props.activeTransaction.id}/return`, { preserveScroll: true, onSuccess: () => (showReturnForm.value = false) });
}

function confirmReceipt() {
    if (!props.activeTransaction) return;
    useForm({}).post(`/assets/custody/${props.activeTransaction.id}/confirm-receipt`, { preserveScroll: true });
}
function requestReturn() {
    if (!props.activeTransaction) return;
    useForm({}).post(`/assets/custody/${props.activeTransaction.id}/request-return`, { preserveScroll: true });
}

const incidentForm = useForm({
    incident_type: 'damage',
    description: '',
    estimated_repair_cost: '',
});
function submitIncident() {
    incidentForm.post(`/assets/${props.asset.id}/incidents`, { preserveScroll: true, onSuccess: () => (showIncidentForm.value = false) });
}

const decisionForms = new Map<string, ReturnType<typeof useForm>>();
function decisionForm(incident: Incident) {
    if (!decisionForms.has(incident.id)) {
        decisionForms.set(incident.id, useForm({ decision: 'repair', reason: '', target_version: incident.version }));
    }
    return decisionForms.get(incident.id)!;
}
function submitDecision(incident: Incident) {
    decisionForm(incident).post(`/assets/incidents/${incident.id}/decide`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="asset.name" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/assets" class="text-muted-foreground text-sm hover:underline">← აქტივები</Link>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold">{{ asset.name }}</h1>
                <StatusBadge :label="conditionLabel[asset.condition] ?? asset.condition" :tone="asset.condition === 'good' || asset.condition === 'new' ? 'success' : 'warning'" />
            </div>
            <p class="text-muted-foreground text-sm">{{ asset.category }} · {{ asset.inventory_code }} · {{ asset.tracking_type }}</p>
        </div>

        <!-- Current custody -->
        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="mb-3 font-semibold">მიმდინარე მდგომარეობა</h2>
            <div v-if="activeTransaction" class="flex flex-col gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <StatusBadge :label="statusLabel[activeTransaction.status] ?? activeTransaction.status" tone="info" />
                    <span v-if="activeTransaction.receiving_employee_name" class="text-sm">მფლობელი: {{ activeTransaction.receiving_employee_name }}</span>
                </div>
                <p v-if="activeTransaction.return_requested_at" class="text-muted-foreground text-sm">დაბრუნება მოთხოვნილია.</p>

                <div class="mt-2 flex flex-wrap gap-2">
                    <Button
                        v-if="['awaiting_receipt', 'in_transit'].includes(activeTransaction.status)"
                        size="sm"
                        @click="confirmReceipt"
                    >
                        მიღების დადასტურება
                    </Button>
                    <Button
                        v-if="activeTransaction.status === 'issued' && !activeTransaction.return_requested_at"
                        variant="outline"
                        size="sm"
                        @click="requestReturn"
                    >
                        დაბრუნების მოთხოვნა
                    </Button>
                    <Button
                        v-if="can.manageCustody && ['issued', 'partially_returned'].includes(activeTransaction.status)"
                        variant="outline"
                        size="sm"
                        @click="showTransferForm = !showTransferForm"
                    >
                        გადაცემა
                    </Button>
                    <Button
                        v-if="can.manageCustody && ['issued', 'partially_returned'].includes(activeTransaction.status)"
                        variant="outline"
                        size="sm"
                        @click="openReturnForm"
                    >
                        დაბრუნება
                    </Button>
                </div>
            </div>
            <div v-else class="flex flex-col gap-2">
                <p class="text-muted-foreground text-sm">აქტივი ხელმისაწვდომია გასაცემად.</p>
                <Button v-if="can.manageCustody" size="sm" class="w-fit" @click="showIssueForm = !showIssueForm">გაცემა</Button>
            </div>

            <form v-if="showIssueForm" class="mt-4 grid gap-3 border-t pt-4" @submit.prevent="submitIssue">
                <div class="grid gap-2">
                    <Label for="issue-employee">მიმღები თანამშრომელი</Label>
                    <select id="issue-employee" v-model="issueForm.receiving_employee_id" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="">აირჩიეთ</option>
                        <option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.first_name }} {{ employee.last_name }}</option>
                    </select>
                    <InputError :message="issueForm.errors.receiving_employee_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="issue-condition">მდგომარეობა გაცემის დროს</Label>
                    <select id="issue-condition" v-model="issueForm.condition_at_transaction" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="new">ახალი</option>
                        <option value="good">კარგი</option>
                        <option value="fair">დამაკმაყოფილებელი</option>
                        <option value="damaged">დაზიანებული</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="issue-expected-return">მოსალოდნელი დაბრუნების თარიღი</Label>
                    <Input id="issue-expected-return" v-model="issueForm.expected_return_at" type="date" />
                </div>
                <div class="grid gap-2">
                    <Label for="issue-comment">კომენტარი</Label>
                    <Textarea id="issue-comment" v-model="issueForm.comment" />
                </div>
                <InputError :message="(issueForm.errors as Record<string, string>).custody" />
                <div class="flex gap-2">
                    <Button type="submit" :disabled="issueForm.processing">გაცემის დადასტურება</Button>
                    <Button type="button" variant="ghost" @click="showIssueForm = false">გაუქმება</Button>
                </div>
            </form>

            <form v-if="showTransferForm" class="mt-4 grid gap-3 border-t pt-4" @submit.prevent="submitTransfer">
                <div class="grid gap-2">
                    <Label for="transfer-employee">ახალი მიმღები</Label>
                    <select id="transfer-employee" v-model="transferForm.receiving_employee_id" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="">აირჩიეთ</option>
                        <option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.first_name }} {{ employee.last_name }}</option>
                    </select>
                    <InputError :message="transferForm.errors.receiving_employee_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="transfer-comment">კომენტარი</Label>
                    <Textarea id="transfer-comment" v-model="transferForm.comment" />
                </div>
                <InputError :message="(transferForm.errors as Record<string, string>).custody" />
                <div class="flex gap-2">
                    <Button type="submit" :disabled="transferForm.processing">გადაცემის დადასტურება</Button>
                    <Button type="button" variant="ghost" @click="showTransferForm = false">გაუქმება</Button>
                </div>
            </form>

            <form v-if="showReturnForm && activeTransaction" class="mt-4 grid gap-3 border-t pt-4" @submit.prevent="submitReturn">
                <div v-for="(line, index) in returnForm.lines" :key="line.custody_line_id" class="grid gap-2 sm:grid-cols-2">
                    <div class="grid gap-1">
                        <Label>რაოდენობა</Label>
                        <Input v-model.number="returnForm.lines[index].quantity" type="number" min="0.01" step="0.01" />
                    </div>
                    <div class="grid gap-1">
                        <Label>მდგომარეობა დაბრუნებისას</Label>
                        <select v-model="returnForm.lines[index].condition" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                            <option value="new">ახალი</option>
                            <option value="good">კარგი</option>
                            <option value="fair">დამაკმაყოფილებელი</option>
                            <option value="damaged">დაზიანებული</option>
                        </select>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="return-comment">კომენტარი</Label>
                    <Textarea id="return-comment" v-model="returnForm.comment" />
                </div>
                <InputError :message="(returnForm.errors as Record<string, string>).custody" />
                <div class="flex gap-2">
                    <Button type="submit" :disabled="returnForm.processing">დაბრუნების დადასტურება</Button>
                    <Button type="button" variant="ghost" @click="showReturnForm = false">გაუქმება</Button>
                </div>
            </form>
        </section>

        <!-- Incidents -->
        <section class="border-border bg-card rounded-xl border p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold">ინციდენტები</h2>
                <Button v-if="can.reportIncident" variant="outline" size="sm" @click="showIncidentForm = !showIncidentForm">ინციდენტის დაფიქსირება</Button>
            </div>

            <form v-if="showIncidentForm" class="mb-4 grid gap-3 border-b pb-4" @submit.prevent="submitIncident">
                <div class="grid gap-2">
                    <Label for="incident-type">ტიპი</Label>
                    <select id="incident-type" v-model="incidentForm.incident_type" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="damage">დაზიანება</option>
                        <option value="loss">დაკარგვა</option>
                        <option value="write_off_request">ჩამოწერის მოთხოვნა</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="incident-description">აღწერა</Label>
                    <Textarea id="incident-description" v-model="incidentForm.description" required />
                    <InputError :message="incidentForm.errors.description" />
                </div>
                <div class="grid gap-2">
                    <Label for="incident-cost">სავარაუდო ღირებულება</Label>
                    <Input id="incident-cost" v-model="incidentForm.estimated_repair_cost" type="number" min="0" step="0.01" />
                </div>
                <div class="flex gap-2">
                    <Button type="submit" :disabled="incidentForm.processing">გაგზავნა</Button>
                    <Button type="button" variant="ghost" @click="showIncidentForm = false">გაუქმება</Button>
                </div>
            </form>

            <EmptyState v-if="incidents.length === 0" title="ინციდენტები არ დაფიქსირებულა" />
            <div v-else class="divide-border divide-y">
                <div v-for="incident in incidents" :key="incident.id" class="py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ incidentTypeLabel[incident.incident_type] ?? incident.incident_type }}</span>
                        <StatusBadge v-if="incident.decision" :label="decisionLabel[incident.decision] ?? incident.decision" tone="neutral" />
                        <StatusBadge v-else label="განხილვის მოლოდინში" tone="warning" />
                    </div>
                    <p class="text-muted-foreground text-sm">{{ incident.description }}</p>
                    <p class="text-muted-foreground text-xs">დააფიქსირა: {{ incident.reported_by_name }}</p>

                    <form
                        v-if="!incident.decision && can.decideIncident"
                        class="mt-2 flex flex-wrap items-end gap-2"
                        @submit.prevent="submitDecision(incident)"
                    >
                        <select v-model="decisionForm(incident).decision" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                            <option value="repair">შეკეთება</option>
                            <option value="write_off">ჩამოწერა</option>
                            <option value="no_action">ქმედების გარეშე</option>
                        </select>
                        <Input v-model="decisionForm(incident).reason" placeholder="მიზეზი" class="max-w-xs" />
                        <Button type="submit" size="sm" :disabled="decisionForm(incident).processing">გადაწყვეტილება</Button>
                    </form>
                </div>
            </div>
        </section>

        <!-- Custody history -->
        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="mb-3 font-semibold">ისტორია</h2>
            <EmptyState v-if="custodyHistory.length === 0" title="ისტორია ცარიელია" />
            <div v-else class="divide-border divide-y">
                <div v-for="transaction in custodyHistory" :key="transaction.id" class="flex flex-wrap items-center gap-2 py-2 text-sm">
                    <StatusBadge :label="statusLabel[transaction.status] ?? transaction.status" tone="neutral" />
                    <span>{{ transaction.type }}</span>
                    <span v-if="transaction.receiving_employee_name" class="text-muted-foreground">→ {{ transaction.receiving_employee_name }}</span>
                    <span class="text-muted-foreground ml-auto">{{ transaction.occurred_at?.slice(0, 10) }}</span>
                </div>
            </div>
        </section>
    </div>
</template>
