<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TimesheetEmailBatchHistory from '@/components/Timesheets/TimesheetEmailBatchHistory.vue';

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
    canSendBatch: boolean;
    filteredTotal: number;
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

// --- TIMESHEET-EMAIL-02: selection + batch send ---

const selectedIds = ref(new Set<string>());
const selectAllFiltered = ref(false);
const pageIds = computed(() => props.timesheets.data.map((t) => t.id));
const pageFullySelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selectedIds.value.has(id)));
const selectedCount = computed(() => (selectAllFiltered.value ? props.filteredTotal : selectedIds.value.size));

function togglePageSelection() {
    if (pageFullySelected.value) {
        pageIds.value.forEach((id) => selectedIds.value.delete(id));
    } else {
        pageIds.value.forEach((id) => selectedIds.value.add(id));
    }
    selectAllFiltered.value = false;
}

function toggleRow(id: string) {
    if (selectedIds.value.has(id)) {
        selectedIds.value.delete(id);
    } else {
        selectedIds.value.add(id);
    }
    selectAllFiltered.value = false;
}

function selectAllFilteredResults() {
    selectAllFiltered.value = true;
    selectedIds.value = new Set(pageIds.value);
}

function clearSelection() {
    selectedIds.value = new Set();
    selectAllFiltered.value = false;
}

const dialogOpen = ref(false);
const batchForm = useForm({
    mode: 'per_employee' as 'per_employee' | 'bundled',
    bundled_recipient_email: '',
    bundled_recipient_user_id: '' as string | null,
    select_all_filtered: false,
    timesheet_ids: [] as string[],
    filters: { employee_id: props.filters.employee_id, status: props.filters.status },
});

function openBatchDialog() {
    batchForm.select_all_filtered = selectAllFiltered.value;
    batchForm.timesheet_ids = selectAllFiltered.value ? [] : Array.from(selectedIds.value);
    batchForm.filters = { employee_id: props.filters.employee_id, status: props.filters.status };
    dialogOpen.value = true;
}

function submitBatch() {
    batchForm.post('/timesheet-email-batches', {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            clearSelection();
            router.reload({ only: ['timesheets'] });
        },
    });
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

        <div v-if="canSendBatch && selectedCount > 0" class="border-primary bg-primary/5 flex flex-wrap items-center justify-between gap-2 rounded-xl border p-3 text-sm">
            <p>
                მონიშნულია <strong>{{ selectedCount }}</strong> ტაბელი{{ selectAllFiltered ? ' (ყველა ფილტრის შედეგი)' : '' }}.
            </p>
            <div class="flex gap-2">
                <Button type="button" variant="outline" size="sm" @click="clearSelection">გასუფთავება</Button>
                <Button type="button" size="sm" @click="openBatchDialog">მონიშნული ტაბელების გაგზავნა</Button>
            </div>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="timesheets.data.length === 0" title="ტაბელი არ მოიძებნა" description="დაგენერირეთ ტაბელი ზემოთ მოცემული ფორმით." />
            <template v-else>
                <div v-if="canSendBatch" class="border-border bg-muted/30 flex items-center gap-3 border-b px-4 py-2 text-sm">
                    <Checkbox :model-value="pageFullySelected" @update:model-value="togglePageSelection" />
                    <span>ამ გვერდის მონიშვნა</span>
                    <button
                        v-if="filteredTotal > pageIds.length"
                        type="button"
                        class="text-primary ml-2 underline"
                        @click="selectAllFilteredResults"
                    >
                        ან ფილტრის ყველა შედეგის მონიშვნა ({{ filteredTotal }})
                    </button>
                </div>
                <div class="divide-border divide-y">
                    <div
                        v-for="timesheet in timesheets.data"
                        :key="timesheet.id"
                        class="hover:bg-muted/50 grid items-center gap-3 p-4 md:grid-cols-[auto_1fr_1fr_120px_auto]"
                    >
                        <Checkbox
                            v-if="canSendBatch"
                            :model-value="selectedIds.has(timesheet.id)"
                            @update:model-value="toggleRow(timesheet.id)"
                        />
                        <div v-else class="hidden md:block" />
                        <Link :href="`/timesheets/${timesheet.id}`" class="truncate font-medium">{{ timesheet.employee_name }}</Link>
                        <Link :href="`/timesheets/${timesheet.id}`" class="text-muted-foreground text-sm">
                            {{ timesheet.pay_period?.starts_on }} – {{ timesheet.pay_period?.ends_on }}
                        </Link>
                        <Link :href="`/timesheets/${timesheet.id}`" class="text-muted-foreground text-sm">
                            {{ timesheet.lines_sum_payable_minutes ?? 0 }} წთ
                        </Link>
                        <StatusBadge :label="timesheet.status" :tone="statusTone(timesheet.status)" />
                    </div>
                </div>
            </template>
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

        <TimesheetEmailBatchHistory v-if="canSendBatch" />

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>მონიშნული ტაბელების გაგზავნა</DialogTitle>
                    <DialogDescription>{{ selectedCount }} ტაბელი მონიშნულია.</DialogDescription>
                </DialogHeader>

                <form class="flex flex-col gap-4" @submit.prevent="submitBatch">
                    <div class="grid gap-2">
                        <label class="text-sm font-medium">რეჟიმი</label>
                        <div class="flex flex-col gap-2 text-sm">
                            <label class="flex items-center gap-2">
                                <input v-model="batchForm.mode" type="radio" value="per_employee" />
                                თითოეულ თანამშრომელს თავისი ტაბელი, ცალკე წერილით
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="batchForm.mode" type="radio" value="bundled" />
                                ყველა მონიშნული ტაბელი ერთ უფლებამოსილ პასუხისმგებელთან
                            </label>
                        </div>
                    </div>

                    <div v-if="batchForm.mode === 'bundled'" class="grid gap-2">
                        <label class="text-sm font-medium" for="bundled_recipient_email">პასუხისმგებლის ელფოსტა</label>
                        <input
                            id="bundled_recipient_email"
                            v-model="batchForm.bundled_recipient_email"
                            type="email"
                            required
                            class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        />
                        <p v-if="batchForm.errors.bundled_recipient_email" class="text-destructive text-xs">
                            {{ batchForm.errors.bundled_recipient_email }}
                        </p>
                    </div>

                    <p v-if="batchForm.errors.timesheet_ids" class="text-destructive text-xs">{{ batchForm.errors.timesheet_ids }}</p>

                    <DialogFooter>
                        <Button type="submit" :disabled="batchForm.processing">გაგზავნა</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
