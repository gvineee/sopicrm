<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Company = {
    id: string;
    name: string;
    legal_name: string | null;
    code: string | null;
    default_currency: string;
    default_timezone: string;
    is_active: boolean;
    memberships_count: number | null;
};

defineOptions({ layout: { mobileTitle: 'კომპანიები' } });

defineProps<{
    companies: Company[];
    currentCompanyId: string | null;
    canManage: boolean;
}>();
</script>

<template>
    <Head title="კომპანიები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">კომპანიები</h1>
                <p class="text-muted-foreground text-sm">ორგანიზაციის იურიდიული და ოპერაციული კომპანიები.</p>
            </div>
            <Button v-if="canManage" as-child><Link href="/companies/create">კომპანიის დამატება</Link></Button>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="companies.length === 0"
                title="კომპანია არ არის დამატებული"
                description="დაამატეთ პირველი კომპანია საიტების, პროექტებისა და წვდომის მართვამდე."
            />
            <div v-else class="divide-border divide-y">
                <div
                    v-for="company in companies"
                    :key="company.id"
                    class="grid gap-3 p-4 md:grid-cols-[1fr_180px_150px_auto] md:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate font-medium">{{ company.name }}</p>
                            <StatusBadge v-if="company.id === currentCompanyId" label="მიმდინარე" tone="info" />
                        </div>
                        <p class="text-muted-foreground truncate text-sm">
                            {{ company.legal_name || 'იურიდიული დასახელება მითითებული არ არის' }}
                        </p>
                    </div>
                    <p class="text-muted-foreground text-sm">{{ company.code || 'კოდის გარეშე' }}</p>
                    <StatusBadge
                        :label="company.is_active ? 'აქტიური' : 'გათიშული'"
                        :tone="company.is_active ? 'success' : 'neutral'"
                    />
                    <Button v-if="canManage" variant="outline" size="sm" as-child>
                        <Link :href="`/companies/${company.id}/edit`">რედაქტირება</Link>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
