<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { assetTrackingTypeLabel } from '@/lib/labels';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Asset = {
    id: string;
    name: string;
    category: string;
    tracking_type: string;
    inventory_code: string;
    condition: string;
    active_custody_status: string | null;
};

const props = defineProps<{
    assets: Asset[];
    meta: { page: number; perPage: number; total: number };
    filters: { search: string | null; tracking_type: string | null; condition: string | null };
    canCreate: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'აქტივები' } });

const search = ref(props.filters.search ?? '');

const conditionTone: Record<string, 'success' | 'warning' | 'destructive' | 'neutral'> = {
    new: 'success',
    good: 'success',
    fair: 'warning',
    damaged: 'destructive',
    under_repair: 'warning',
    written_off: 'destructive',
};

const conditionLabel: Record<string, string> = {
    new: 'ახალი',
    good: 'კარგი',
    fair: 'დამაკმაყოფილებელი',
    damaged: 'დაზიანებული',
    under_repair: 'შეკეთებაშია',
    written_off: 'ჩამოწერილი',
};

const custodyLabel: Record<string, string> = {
    available: 'ხელმისაწვდომი',
    awaiting_receipt: 'მიღების მოლოდინში',
    issued: 'გაცემული',
    in_transit: 'გადაცემის პროცესში',
};

function applyFilters() {
    router.get('/assets', { search: search.value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="აქტივები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">აქტივები</h1>
                <p class="text-muted-foreground text-sm">ინსტრუმენტები, ტექნიკა და მარაგი — გაცემა, დაბრუნება, გადაცემა.</p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" as-child><Link href="/assets/stocktakes">ინვენტარიზაცია</Link></Button>
                <Button v-if="canCreate" as-child><Link href="/assets/create">აქტივის რეგისტრაცია</Link></Button>
            </div>
        </div>

        <div class="flex gap-2">
            <Input v-model="search" placeholder="ძებნა სახელით, კოდით ან სერიული ნომრით" @keyup.enter="applyFilters" />
            <Button variant="outline" @click="applyFilters">ძებნა</Button>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="assets.length === 0"
                title="აქტივი ვერ მოიძებნა"
                description="დაარეგისტრირეთ პირველი აქტივი, რომ დაიწყოთ გაცემის/დაბრუნების აღრიცხვა."
            />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="asset in assets"
                    :key="asset.id"
                    :href="`/assets/${asset.id}`"
                    class="hover:bg-accent grid gap-3 p-4 md:grid-cols-[1fr_140px_160px_140px] md:items-center"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ asset.name }}</p>
                        <p class="text-muted-foreground truncate text-sm">{{ asset.category }} · {{ asset.inventory_code }}</p>
                    </div>
                    <p class="text-muted-foreground text-sm">{{ assetTrackingTypeLabel(asset.tracking_type) }}</p>
                    <StatusBadge :label="conditionLabel[asset.condition] ?? asset.condition" :tone="conditionTone[asset.condition] ?? 'neutral'" />
                    <StatusBadge
                        v-if="asset.active_custody_status"
                        :label="custodyLabel[asset.active_custody_status] ?? asset.active_custody_status"
                        tone="info"
                    />
                </Link>
            </div>
        </div>

        <p class="text-muted-foreground text-sm">სულ: {{ meta.total }}</p>
    </div>
</template>
