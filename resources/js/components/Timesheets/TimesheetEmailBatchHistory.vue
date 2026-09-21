<script setup lang="ts">
/**
 * TIMESHEET-EMAIL-02: recent batch send history + live progress
 * (total/queued/sent/failed/skipped, per the ticket's own explicit
 * requirement) and per-delivery retry/cancel controls. Fetched client-side
 * (not an Inertia prop) so this panel can be refreshed independently of a
 * full page reload while a batch is still resolving.
 */
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import StatusBadge from '@/components/StatusBadge.vue';

type Delivery = {
    id: string;
    recipient_email: string;
    effective_status: 'queued' | 'sent' | 'failed' | 'cancelled';
    failed_reason: string | null;
    item_count: number | null;
};

type Batch = {
    id: string;
    mode: 'per_employee' | 'bundled';
    status: string;
    total_count: number;
    skipped_count: number;
    queued_count: number;
    sent_count: number;
    failed_count: number;
    cancelled_count: number;
    skipped_details: { timesheet_id: string | null; recipient_email: string | null; reason: string }[];
    requested_by_name: string | null;
    deliveries: Delivery[];
    created_at: string;
};

const batches = ref<Batch[]>([]);
const loading = ref(true);

async function load() {
    loading.value = true;
    const response = await fetch('/timesheet-email-batches', { headers: { Accept: 'application/json' } });
    const data = await response.json();
    batches.value = data.batches;
    loading.value = false;
}

function statusTone(status: Delivery['effective_status']): 'success' | 'warning' | 'neutral' | 'destructive' {
    if (status === 'sent') return 'success';
    if (status === 'failed') return 'destructive';
    if (status === 'cancelled') return 'neutral';
    return 'warning';
}

async function retry(batchId: string, deliveryId: string) {
    await fetch(`/timesheet-email-batches/${batchId}/deliveries/${deliveryId}/retry`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            Accept: 'application/json',
        },
    });
    await load();
}

async function cancel(batchId: string) {
    await fetch(`/timesheet-email-batches/${batchId}/cancel`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            Accept: 'application/json',
        },
    });
    await load();
}

onMounted(load);
</script>

<template>
    <div class="border-border bg-card flex flex-col gap-4 rounded-xl border p-5">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold">ჯგუფური გაგზავნების ისტორია</h2>
            <Button type="button" variant="outline" size="sm" @click="load">განახლება</Button>
        </div>

        <p v-if="loading" class="text-muted-foreground text-sm">იტვირთება...</p>
        <p v-else-if="batches.length === 0" class="text-muted-foreground text-sm">ჯერ არცერთი ჯგუფური გაგზავნა არ ყოფილა.</p>

        <div v-for="batch in batches" :key="batch.id" class="border-border rounded-lg border p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="text-sm">
                    <p class="font-medium">
                        {{ batch.mode === 'bundled' ? 'ერთ პასუხისმგებელთან' : 'თითოეულ თანამშრომელს ცალკე' }}
                        — {{ batch.total_count }} ტაბელი
                    </p>
                    <p class="text-muted-foreground">{{ batch.requested_by_name }} · {{ new Date(batch.created_at).toLocaleString('ka-GE') }}</p>
                </div>
                <Button
                    v-if="batch.queued_count > 0"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="cancel(batch.id)"
                >
                    დარჩენილის გაუქმება
                </Button>
            </div>

            <div class="mt-2 flex flex-wrap gap-3 text-xs">
                <span>სულ: {{ batch.total_count }}</span>
                <span class="text-amber-600">რიგში: {{ batch.queued_count }}</span>
                <span class="text-emerald-600">გაგზავნილი: {{ batch.sent_count }}</span>
                <span class="text-destructive">ვერ გაიგზავნა: {{ batch.failed_count }}</span>
                <span v-if="batch.cancelled_count > 0">გაუქმებული: {{ batch.cancelled_count }}</span>
                <span v-if="batch.skipped_count > 0">გამოტოვებული: {{ batch.skipped_count }}</span>
            </div>

            <details v-if="batch.skipped_count > 0" class="mt-2 text-xs">
                <summary class="text-muted-foreground cursor-pointer">გამოტოვებული ჩანაწერები</summary>
                <ul class="mt-1 list-disc pl-4">
                    <li v-for="(skip, i) in batch.skipped_details" :key="i">
                        {{ skip.recipient_email ?? skip.timesheet_id }} — {{ skip.reason }}
                    </li>
                </ul>
            </details>

            <div class="mt-3 flex flex-col gap-1">
                <div v-for="delivery in batch.deliveries" :key="delivery.id" class="flex items-center justify-between gap-2 text-xs">
                    <span>{{ delivery.recipient_email }}<span v-if="delivery.item_count && delivery.item_count > 1"> ({{ delivery.item_count }} დოკუმენტი)</span></span>
                    <div class="flex items-center gap-2">
                        <StatusBadge :label="delivery.effective_status" :tone="statusTone(delivery.effective_status)" />
                        <Button v-if="delivery.effective_status === 'failed'" type="button" variant="outline" size="sm" @click="retry(batch.id, delivery.id)">
                            ხელახლა
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
