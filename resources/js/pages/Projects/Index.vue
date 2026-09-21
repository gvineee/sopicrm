<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import type { StatusTone } from '@/types';

type Project = {
    id: string;
    code: string;
    name: string;
    status: string;
    address?: string | null;
    starts_on?: string | null;
    ends_on?: string | null;
    client?: { id: string; name: string } | null;
    company?: { id: string; name: string } | null;
    manager?: { id: string; name: string } | null;
    members_count?: number;
};

const props = defineProps<{
    projects: Project[];
    pagination: { page: number; perPage: number; total: number };
    filters: { search: string; status: string; client_id: string; company_id: string };
    sort: { key: string; direction: string };
    clients: Array<{ id: string; name: string }>;
    companies: Array<{ id: string; name: string; code?: string | null }>;
    can: { create: boolean; view_any: boolean };
}>();

const hasActiveFilters = props.filters.search !== '' || props.filters.status !== '' || props.filters.client_id !== '' || props.filters.company_id !== '';

defineOptions({ layout: { mobileTitle: 'პროექტები' } });

const STATUS_LABEL: Record<string, string> = {
    planning: 'დაგეგმვა',
    active: 'აქტიური',
    on_hold: 'შეჩერებული',
    completed: 'დასრულებული',
    cancelled: 'გაუქმებული',
};
const STATUS_TONE: Record<string, StatusTone> = {
    planning: 'neutral',
    active: 'success',
    on_hold: 'warning',
    completed: 'info',
    cancelled: 'destructive',
};

const lastPage = Math.max(1, Math.ceil(props.pagination.total / props.pagination.perPage));
</script>

<template>
    <Head title="პროექტები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">პროექტები</h1>
                <p class="text-muted-foreground text-sm">
                    {{ can.view_any ? 'კომპანიის ყველა პროექტი.' : 'პროექტები, რომლებშიც წევრი ხართ.' }}
                </p>
            </div>
            <Button v-if="can.create" as-child><Link href="/projects/create">პროექტის დამატება</Link></Button>
        </div>

        <form method="get" action="/projects" class="border-border bg-card grid gap-3 rounded-xl border p-4 md:grid-cols-5">
            <Input name="search" :default-value="filters.search" placeholder="სახელი ან კოდი" class="md:col-span-2" />
            <select name="status" :value="filters.status" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                <option value="">ყველა სტატუსი</option>
                <option value="planning">დაგეგმვა</option>
                <option value="active">აქტიური</option>
                <option value="on_hold">შეჩერებული</option>
                <option value="completed">დასრულებული</option>
                <option value="cancelled">გაუქმებული</option>
            </select>
            <select name="company_id" :value="filters.company_id" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                <option value="">ყველა კომპანია</option>
                <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
            </select>
            <select name="client_id" :value="filters.client_id" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                <option value="">ყველა კლიენტი</option>
                <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
            </select>
            <div class="flex gap-2 md:col-span-5">
                <Button type="submit" variant="outline">ძიება</Button>
                <Button v-if="hasActiveFilters" as-child variant="ghost"><Link href="/projects">გასუფთავება</Link></Button>
            </div>
        </form>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="projects.length === 0"
                :title="hasActiveFilters ? 'ფილტრით პროექტი ვერ მოიძებნა' : 'პროექტი ვერ მოიძებნა'"
                :description="hasActiveFilters ? 'სცადეთ ფილტრების შეცვლა ან გასუფთავება.' : (can.create ? 'დაამატეთ პირველი პროექტი.' : 'ჯერ არცერთ პროექტში არ ხართ წევრი.')"
            />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="project in projects"
                    :key="project.id"
                    :href="`/projects/${project.id}`"
                    class="hover:bg-muted/40 grid gap-2 p-4 transition-colors md:grid-cols-[1fr_160px_160px_140px] md:items-center"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ project.name }}</p>
                        <p class="text-muted-foreground truncate text-sm">
                            {{ project.code }} · {{ project.client?.name || 'კლიენტი მიუთითებელია' }}
                        </p>
                    </div>
                    <p class="text-muted-foreground truncate text-sm">{{ project.company?.name || 'კომპანია მიუთითებელია' }}</p>
                    <p class="text-muted-foreground text-sm">{{ project.manager?.name || 'მენეჯერი მიუთითებელია' }}</p>
                    <StatusBadge :label="STATUS_LABEL[project.status] || project.status" :tone="STATUS_TONE[project.status] || 'neutral'" />
                </Link>
            </div>
        </div>

        <div class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground">გვერდი {{ pagination.page }} / {{ lastPage }} · სულ {{ pagination.total }}</span>
        </div>
    </div>
</template>
