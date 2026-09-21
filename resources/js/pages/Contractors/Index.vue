<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Contractor = {
    id: string;
    name: string;
    legal_name: string | null;
    contact_person: string | null;
    phone: string | null;
    default_currency: string;
    is_active: boolean;
    contracts_count: number | null;
};

defineOptions({ layout: { mobileTitle: 'კონტრაქტორები' } });

defineProps<{
    contractors: Contractor[];
    canManage: boolean;
}>();
</script>

<template>
    <Head title="კონტრაქტორები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">კონტრაქტორები</h1>
                <p class="text-muted-foreground text-sm">გარე კომპანიები და გუნდები, რომლებიც კონკრეტულ დავალებებზე გვემსახურებიან.</p>
            </div>
            <Button v-if="canManage" as-child><Link href="/contractors/create">კონტრაქტორის დამატება</Link></Button>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="contractors.length === 0"
                title="კონტრაქტორი არ არის დამატებული"
                description="დაამატეთ პირველი კონტრაქტორი კონტრაქტისა და დავალებაზე მინიჭებამდე."
            />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="contractor in contractors"
                    :key="contractor.id"
                    :href="`/contractors/${contractor.id}`"
                    class="hover:bg-muted/50 grid gap-3 p-4 md:grid-cols-[1fr_180px_120px_auto] md:items-center"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ contractor.name }}</p>
                        <p class="text-muted-foreground truncate text-sm">
                            {{ contractor.contact_person || contractor.legal_name || 'დამატებითი ინფორმაციის გარეშე' }}
                        </p>
                    </div>
                    <p class="text-muted-foreground text-sm">{{ contractor.phone || '—' }}</p>
                    <StatusBadge
                        :label="contractor.is_active ? 'აქტიური' : 'გათიშული'"
                        :tone="contractor.is_active ? 'success' : 'neutral'"
                    />
                    <p class="text-muted-foreground text-sm">{{ contractor.contracts_count ?? 0 }} კონტრაქტი</p>
                </Link>
            </div>
        </div>
    </div>
</template>
