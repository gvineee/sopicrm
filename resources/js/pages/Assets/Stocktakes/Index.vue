<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Site = { id: string; name: string };
type Stocktake = {
    id: string;
    scope_type: string;
    scope_id: string;
    session_started_at: string | null;
    status: string;
    performed_by_name: string | null;
};

const props = defineProps<{
    stocktakes: Stocktake[];
    meta: { page: number; perPage: number; total: number };
    sites: Site[];
    canPerform: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'ინვენტარიზაცია' } });

const statusLabel: Record<string, string> = {
    in_progress: 'მიმდინარეობს',
    completed: 'დასრულებული',
};

const dialogOpen = ref(false);
const form = useForm({ scope_type: 'site', scope_id: '' });

function siteName(id: string): string {
    return props.sites.find((s) => s.id === id)?.name ?? id;
}

function submit() {
    form.post('/assets/stocktakes', { onSuccess: () => (dialogOpen.value = false) });
}
</script>

<template>
    <Head title="ინვენტარიზაცია" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">ინვენტარიზაცია</h1>
                <p class="text-muted-foreground text-sm">ფიზიკური დათვლის სესიები საწყობის/ობიექტის მიხედვით.</p>
            </div>
            <Button v-if="canPerform" @click="dialogOpen = true">ინვენტარიზაციის დაწყება</Button>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="stocktakes.length === 0" title="ინვენტარიზაცია ჯერ არ ჩატარებულა" />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="stocktake in stocktakes"
                    :key="stocktake.id"
                    :href="`/assets/stocktakes/${stocktake.id}`"
                    class="hover:bg-accent grid gap-3 p-4 md:grid-cols-[1fr_160px_160px] md:items-center"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ siteName(stocktake.scope_id) }}</p>
                        <p class="text-muted-foreground truncate text-sm">
                            დაიწყო: {{ stocktake.performed_by_name ?? '—' }}
                        </p>
                    </div>
                    <p class="text-muted-foreground text-sm">{{ stocktake.session_started_at?.slice(0, 16).replace('T', ' ') }}</p>
                    <StatusBadge
                        :label="statusLabel[stocktake.status] ?? stocktake.status"
                        :tone="stocktake.status === 'completed' ? 'success' : 'info'"
                    />
                </Link>
            </div>
        </div>

        <p class="text-muted-foreground text-sm">სულ: {{ meta.total }}</p>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>ინვენტარიზაციის დაწყება</DialogTitle>
            </DialogHeader>
            <div class="flex flex-col gap-3">
                <div>
                    <Label>ობიექტი</Label>
                    <select v-model="form.scope_id" class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm">
                        <option value="" disabled>აირჩიეთ ობიექტი</option>
                        <option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }}</option>
                    </select>
                </div>
                <p v-if="form.errors.scope_id" class="text-destructive text-sm">{{ form.errors.scope_id }}</p>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="dialogOpen = false">გაუქმება</Button>
                <Button :disabled="!form.scope_id || form.processing" @click="submit">დაწყება</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
