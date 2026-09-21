<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Employee = { id: string; first_name: string; last_name: string };
type Adjustment = {
    id: string;
    employee_name: string | null;
    work_date: string;
    reason: string;
    status: string;
    version: number;
    for_locked_period: boolean;
};

defineOptions({ layout: { mobileTitle: 'დასწრების შესწორებები' } });

const props = defineProps<{
    adjustments: { data: Adjustment[]; links: { url: string | null; label: string; active: boolean }[] };
    employees: Employee[];
    canRequest: boolean;
}>();

const form = useForm({
    employee_id: '',
    work_date: new Date().toISOString().slice(0, 10),
    corrected_clock_in_at: '',
    corrected_clock_out_at: '',
    corrected_hours: '',
    reason: '',
});

function submit() {
    form.post('/attendance-adjustments', {
        preserveScroll: true,
        onSuccess: () => form.reset('corrected_clock_in_at', 'corrected_clock_out_at', 'corrected_hours', 'reason'),
    });
}

function decide(adjustment: Adjustment, decision: 'approved' | 'rejected') {
    useForm({ version: adjustment.version, decision }).post(`/attendance-adjustments/${adjustment.id}/decide`, { preserveScroll: true });
}

function statusTone(status: string): 'success' | 'warning' | 'destructive' {
    if (status === 'approved') return 'success';
    if (status === 'rejected') return 'destructive';
    return 'warning';
}
</script>

<template>
    <Head title="დასწრების შესწორებები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">დასწრების შესწორებები</h1>
            <p class="text-muted-foreground text-sm">ხელით შესწორების მოთხოვნები — ორიგინალი სესია არასდროს იცვლება.</p>
        </div>

        <form v-if="canRequest" class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>თანამშრომელი</Label>
                    <select v-model="form.employee_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                        <option value="" disabled>აირჩიეთ</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.first_name }} {{ e.last_name }}</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>თარიღი</Label>
                    <Input v-model="form.work_date" type="date" required />
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label>შესწორებული შესვლა</Label>
                    <Input v-model="form.corrected_clock_in_at" type="datetime-local" />
                </div>
                <div class="grid gap-2">
                    <Label>შესწორებული გასვლა</Label>
                    <Input v-model="form.corrected_clock_out_at" type="datetime-local" />
                </div>
                <div class="grid gap-2">
                    <Label>ან საათები</Label>
                    <Input v-model="form.corrected_hours" type="number" step="0.01" min="0" />
                </div>
            </div>
            <div class="grid gap-2">
                <Label>მიზეზი</Label>
                <textarea v-model="form.reason" required rows="2" class="border-input bg-background rounded-md border px-3 py-2 text-sm"></textarea>
            </div>
            <Button type="submit" :disabled="form.processing">მოთხოვნის გაგზავნა</Button>
        </form>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="adjustments.data.length === 0" title="შესწორება არ არის მოთხოვნილი" description="ხელით შესწორება არ დაფიქსირებულა." />
            <div v-else class="divide-border divide-y">
                <div v-for="adjustment in adjustments.data" :key="adjustment.id" class="grid gap-3 p-4 md:grid-cols-[1fr_120px_1fr_120px_auto] md:items-center">
                    <p class="truncate font-medium">{{ adjustment.employee_name }}</p>
                    <p class="text-muted-foreground text-sm">{{ adjustment.work_date }}</p>
                    <p class="text-muted-foreground truncate text-sm">{{ adjustment.reason }}</p>
                    <StatusBadge :label="adjustment.for_locked_period ? 'ჩაკეტილი პერიოდი' : adjustment.status" :tone="statusTone(adjustment.status)" />
                    <div v-if="adjustment.status === 'pending'" class="flex gap-2">
                        <button type="button" class="text-sm hover:underline" @click="decide(adjustment, 'approved')">დამტკიცება</button>
                        <button type="button" class="text-destructive text-sm hover:underline" @click="decide(adjustment, 'rejected')">უარყოფა</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
