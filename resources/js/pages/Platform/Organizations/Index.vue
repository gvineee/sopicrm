<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { formatDate } from '@/lib/labels';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Organization = {
    id: string;
    name: string;
    legal_name: string | null;
    is_active: boolean;
    is_current: boolean;
    created_at: string | null;
    users_count: number;
    employees_count: number;
    projects_count: number;
};

defineProps<{
    organizations: Organization[];
}>();

defineOptions({ layout: { mobileTitle: 'ორგანიზაციები' } });
</script>

<template>
    <Head title="ორგანიზაციები" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">ორგანიზაციები</h1>
                <p class="text-muted-foreground text-sm">
                    პლატფორმის ყველა ორგანიზაცია. რაოდენობები გეხმარებათ გაარჩიოთ რეალური ორგანიზაცია სატესტოსგან.
                </p>
            </div>
            <Button as-child><Link href="/platform/organizations/create">ორგანიზაციის დამატება</Link></Button>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="organizations.length === 0" title="ორგანიზაცია არ არის" description="დაამატეთ პირველი ორგანიზაცია." />
            <div v-else class="divide-border divide-y">
                <div
                    v-for="organization in organizations"
                    :key="organization.id"
                    class="grid gap-3 p-4 md:grid-cols-[1fr_auto_auto] md:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate font-medium">{{ organization.name }}</p>
                            <StatusBadge v-if="organization.is_current" label="მიმდინარე" tone="info" />
                            <StatusBadge v-if="!organization.is_active" label="გათიშული" tone="neutral" />
                        </div>
                        <p class="text-muted-foreground truncate text-sm">
                            {{ organization.legal_name || 'იურიდიული დასახელება მითითებული არ არის' }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            შექმნილია {{ formatDate(organization.created_at) }} · ID …{{ organization.id.slice(-8) }}
                        </p>
                    </div>
                    <dl class="grid grid-cols-3 gap-4 text-center text-sm">
                        <div>
                            <dt class="text-muted-foreground text-xs">მომხმარებელი</dt>
                            <dd class="font-medium tabular-nums">{{ organization.users_count }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">თანამშრომელი</dt>
                            <dd class="font-medium tabular-nums">{{ organization.employees_count }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">პროექტი</dt>
                            <dd class="font-medium tabular-nums">{{ organization.projects_count }}</dd>
                        </div>
                    </dl>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="`/platform/organizations/${organization.id}/edit`">მართვა</Link>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
