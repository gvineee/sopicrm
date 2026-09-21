<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import StatusBadge from '@/components/StatusBadge.vue';

type Line = {
    id: string;
    asset_id: string;
    asset_name: string | null;
    inventory_code: string | null;
    expected_quantity: number;
    counted_quantity: number | null;
    has_variance: boolean;
    variance_resolved: boolean;
    recount_of_line_id: string | null;
};

type Stocktake = {
    id: string;
    scope_type: string;
    scope_id: string;
    status: string;
    lines: Line[];
};

const props = defineProps<{
    stocktake: Stocktake;
    can: { count: boolean; approve: boolean; complete: boolean };
}>();

defineOptions({ layout: { mobileTitle: 'ინვენტარიზაცია' } });

const countInputs = ref<Record<string, string>>({});
const varianceDialog = ref<{ open: boolean; line: Line | null }>({ open: false, line: null });
const varianceForm = useForm({ adjustment_type: 'quantity_correction', notes: '' });

function submitCount(line: Line, isRecount: boolean) {
    const value = countInputs.value[line.id];
    if (value === undefined || value === '') {
        return;
    }

    router.post(
        `/assets/stocktakes/${props.stocktake.id}/lines/${line.id}/scan`,
        { counted_quantity: value, recount: isRecount },
        { preserveScroll: true },
    );
}

function openVariance(line: Line) {
    varianceForm.reset();
    varianceDialog.value = { open: true, line };
}

function submitVariance() {
    const line = varianceDialog.value.line;
    if (!line) {
        return;
    }

    varianceForm.post(`/assets/stocktakes/${props.stocktake.id}/lines/${line.id}/approve-variance`, {
        preserveScroll: true,
        onSuccess: () => (varianceDialog.value = { open: false, line: null }),
    });
}

function completeStocktake() {
    router.post(`/assets/stocktakes/${props.stocktake.id}/complete`);
}

const unresolvedVariances = () => props.stocktake.lines.filter((l) => l.has_variance && !l.variance_resolved).length;
</script>

<template>
    <Head title="ინვენტარიზაცია" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">ინვენტარიზაციის სესია</h1>
                <StatusBadge
                    :label="stocktake.status === 'completed' ? 'დასრულებული' : 'მიმდინარეობს'"
                    :tone="stocktake.status === 'completed' ? 'success' : 'info'"
                />
            </div>
            <Button
                v-if="can.complete && stocktake.status === 'in_progress'"
                :disabled="unresolvedVariances() > 0"
                @click="completeStocktake"
            >
                სესიის დასრულება
            </Button>
        </div>
        <p v-if="unresolvedVariances() > 0" class="border-warning/40 bg-warning/10 text-warning-foreground rounded-lg border px-3 py-2 text-sm">
            {{ unresolvedVariances() }} დაუმტკიცებელი ვარიაცია რჩება — დასრულებამდე საჭიროა ყველა დამტკიცდეს.
        </p>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <div class="divide-border divide-y">
                <div v-for="line in stocktake.lines" :key="line.id" class="flex flex-col gap-2 p-4 md:flex-row md:items-center md:justify-between">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ line.asset_name ?? line.asset_id }}</p>
                        <p class="text-muted-foreground text-sm">
                            {{ line.inventory_code }} · მოსალოდნელი: {{ line.expected_quantity }}
                            <span v-if="line.counted_quantity !== null"> · დათვლილი: {{ line.counted_quantity }}</span>
                        </p>
                        <StatusBadge v-if="line.has_variance" label="ვარიაცია" :tone="line.variance_resolved ? 'success' : 'destructive'" />
                    </div>

                    <div v-if="can.count && stocktake.status === 'in_progress'" class="flex items-center gap-2">
                        <Input v-model="countInputs[line.id]" type="number" step="0.01" placeholder="დათვლილი რაოდ." class="w-28" />
                        <Button
                            v-if="line.counted_quantity === null"
                            size="sm"
                            variant="outline"
                            @click="submitCount(line, false)"
                        >
                            დათვლა
                        </Button>
                        <Button v-else size="sm" variant="outline" @click="submitCount(line, true)">ხელახლა დათვლა</Button>
                    </div>

                    <Button
                        v-if="can.approve && line.has_variance && !line.variance_resolved"
                        size="sm"
                        @click="openVariance(line)"
                    >
                        ვარიაციის გადაწყვეტა
                    </Button>
                </div>
            </div>
        </div>
    </div>

    <Dialog v-model:open="varianceDialog.open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>ვარიაციის გადაწყვეტა — {{ varianceDialog.line?.asset_name }}</DialogTitle>
            </DialogHeader>
            <div class="flex flex-col gap-3">
                <div>
                    <Label>გადაწყვეტილება</Label>
                    <select
                        v-model="varianceForm.adjustment_type"
                        class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                    >
                        <option value="quantity_correction">რაოდენობის კორექტირება (დაითვალეთ სწორი რაოდენობა)</option>
                        <option value="marked_lost">დაკარგულად მონიშვნა</option>
                        <option value="confirmed_found">ნაპოვნად დადასტურება</option>
                    </select>
                </div>
                <div>
                    <Label>შენიშვნა</Label>
                    <Textarea v-model="varianceForm.notes" rows="3" />
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="varianceDialog.open = false">გაუქმება</Button>
                <Button :disabled="varianceForm.processing" @click="submitVariance">დამტკიცება</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
