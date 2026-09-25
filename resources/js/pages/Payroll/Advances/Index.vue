<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { advanceStatusLabel } from '@/lib/labels';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Employee = { id: string; first_name: string; last_name: string };
type Advance = {
    id: string;
    employee_name: string | null;
    amount: string;
    currency: string;
    reason: string;
    status: string;
    remaining: string;
};

defineOptions({ layout: { mobileTitle: 'ავანსები' } });

defineProps<{ advances: Advance[]; employees: Employee[]; canManage: boolean }>();

const advanceForm = useForm({ employee_id: '', amount: '', currency: 'GEL', reason: '' });
// request_id is generated once per distinct payment attempt (MONEY-01): a
// double-click or network retry resubmits the SAME id, so the backend can
// recognize it as the same payment instead of recording it twice. A fresh id
// is only issued after a successful submission, for the NEXT payment.
const paymentForm = useForm({
    employee_id: '',
    amount: '',
    currency: 'GEL',
    method: 'bank_transfer',
    reference: '',
    deducts_advance_id: '',
    request_id: crypto.randomUUID(),
});

function grantAdvance() {
    advanceForm.post('/payroll/advances', { preserveScroll: true, onSuccess: () => advanceForm.reset() });
}

function recordPayment() {
    paymentForm.transform((d) => ({ ...d, deducts_advance_id: d.deducts_advance_id || null })).post('/payroll/payments', {
        preserveScroll: true,
        onSuccess: () => {
            paymentForm.reset();
            paymentForm.request_id = crypto.randomUUID();
        },
    });
}

function statusTone(status: string): 'success' | 'warning' | 'neutral' {
    if (status === 'fully_deducted') return 'success';
    if (status === 'cancelled') return 'neutral';
    return 'warning';
}
</script>

<template>
    <Head title="ავანსები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">ავანსები და გადახდები</h1>
            <p class="text-muted-foreground text-sm">ავანსი მხოლოდ ერთხელ გამოიქვითება; pending გადახდა ბალანსში არ ითვლება.</p>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <form v-if="canManage" class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="grantAdvance">
                <h2 class="font-medium">ავანსის გაცემა</h2>
                <select v-model="advanceForm.employee_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                    <option value="" disabled>თანამშრომელი</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.first_name }} {{ e.last_name }}</option>
                </select>
                <Input v-model="advanceForm.amount" type="number" step="0.01" min="0.01" placeholder="თანხა" required />
                <textarea v-model="advanceForm.reason" required rows="2" placeholder="მიზეზი" class="border-input bg-background rounded-md border px-3 py-2 text-sm"></textarea>
                <Button type="submit" :disabled="advanceForm.processing">გაცემა</Button>
            </form>

            <form v-if="canManage" class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="recordPayment">
                <h2 class="font-medium">გადახდის დაფიქსირება</h2>
                <select v-model="paymentForm.employee_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                    <option value="" disabled>თანამშრომელი</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.first_name }} {{ e.last_name }}</option>
                </select>
                <Input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" placeholder="თანხა" required />
                <select v-model="paymentForm.deducts_advance_id" class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                    <option value="">ხელფასის ჩათვლა (ავანსი არ იქვითება)</option>
                    <option v-for="a in advances.filter((x) => x.status === 'outstanding')" :key="a.id" :value="a.id">
                        ავანსის დაფარვა — {{ a.employee_name }} ({{ a.remaining }} დარჩენილი)
                    </option>
                </select>
                <Button type="submit" :disabled="paymentForm.processing">გადახდის დაფიქსირება</Button>
                <p v-if="paymentForm.errors.amount" class="text-destructive text-sm">{{ paymentForm.errors.amount }}</p>
                <p v-if="paymentForm.errors.currency" class="text-destructive text-sm">{{ paymentForm.errors.currency }}</p>
                <p v-if="paymentForm.errors.deducts_advance_id" class="text-destructive text-sm">{{ paymentForm.errors.deducts_advance_id }}</p>
            </form>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="advances.length === 0" title="ავანსი არ გაცემულა" description="გაცემული ავანსები აქ გამოჩნდება." />
            <div v-else class="divide-border divide-y">
                <div v-for="advance in advances" :key="advance.id" class="grid gap-3 p-4 md:grid-cols-[1fr_120px_120px_auto] md:items-center">
                    <div>
                        <p class="font-medium">{{ advance.employee_name }}</p>
                        <p class="text-muted-foreground truncate text-sm">{{ advance.reason }}</p>
                    </div>
                    <p class="text-sm">{{ advance.amount }} {{ advance.currency }}</p>
                    <p class="text-muted-foreground text-sm">დარჩენილი: {{ advance.remaining }}</p>
                    <StatusBadge :label="advanceStatusLabel(advance.status)" :tone="statusTone(advance.status)" />
                </div>
            </div>
        </div>
    </div>
</template>
