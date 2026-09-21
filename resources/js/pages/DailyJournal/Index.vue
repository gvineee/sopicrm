<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import type { StatusTone } from '@/types';

type Report = {
    id: string;
    report_date: string;
    status: string;
    version: number;
    responsible_name: string | null;
    headcount_from_attendance: number | null;
    headcount_manual_override: number | null;
};

const props = defineProps<{
    project: { id: string; name: string; code: string | null };
    reports: Report[];
    meta: { page: number; perPage: number; total: number };
    filters: { status: string | null; from: string | null; to: string | null };
    canCreate: boolean;
}>();

const base = `/projects/${props.project.id}/daily-journal`;

const STATUS_LABEL: Record<string, string> = {
    draft: 'შავი ვარიანტი',
    submitted: 'წარდგენილია',
    accepted: 'მიღებულია',
};
const STATUS_TONE: Record<string, StatusTone> = {
    draft: 'neutral',
    submitted: 'warning',
    accepted: 'success',
};

function applyFilters(next: Partial<{ status: string; from: string; to: string }>) {
    router.get(
        base,
        {
            status: next.status !== undefined ? next.status || undefined : props.filters.status ?? undefined,
            from: next.from !== undefined ? next.from || undefined : props.filters.from ?? undefined,
            to: next.to !== undefined ? next.to || undefined : props.filters.to ?? undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
}

function goToPage(page: number) {
    router.get(
        base,
        {
            status: props.filters.status ?? undefined,
            from: props.filters.from ?? undefined,
            to: props.filters.to ?? undefined,
            page,
        },
        { preserveScroll: true, preserveState: true },
    );
}

const lastPage = Math.max(1, Math.ceil(props.meta.total / props.meta.perPage));
</script>

<template>
    <Head :title="`დღიური ჟურნალი — ${project.name}`" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link href="/daily-journal" class="text-muted-foreground text-sm hover:underline">← პროექტები</Link>
                <h1 class="mt-1 text-2xl font-semibold">დღიური ჟურნალი — {{ project.name }}</h1>
            </div>
            <Button v-if="canCreate" as-child>
                <Link :href="`${base}/create`">ახალი ჩანაწერი</Link>
            </Button>
        </div>

        <div class="border-border bg-card grid gap-4 rounded-xl border p-4 sm:grid-cols-4">
            <div class="grid gap-2">
                <Label for="filter-status">სტატუსი</Label>
                <select
                    id="filter-status"
                    :value="filters.status ?? ''"
                    class="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    @change="applyFilters({ status: ($event.target as HTMLSelectElement).value })"
                >
                    <option value="">ყველა</option>
                    <option value="draft">შავი ვარიანტი</option>
                    <option value="submitted">წარდგენილია</option>
                    <option value="accepted">მიღებულია</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-from">დან</Label>
                <Input
                    id="filter-from"
                    type="date"
                    :model-value="filters.from ?? ''"
                    @update:model-value="(v) => applyFilters({ from: String(v) })"
                />
            </div>
            <div class="grid gap-2">
                <Label for="filter-to">მდე</Label>
                <Input
                    id="filter-to"
                    type="date"
                    :model-value="filters.to ?? ''"
                    @update:model-value="(v) => applyFilters({ to: String(v) })"
                />
            </div>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="reports.length === 0"
                title="ჩანაწერი არ მოიძებნა"
                :description="canCreate ? 'შექმენით პირველი დღიური ჩანაწერი ზემოთ მოცემული ღილაკით.' : 'ამ პროექტისთვის ჯერ არცერთი დღიური ჩანაწერი არ არსებობს.'"
            />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="report in reports"
                    :key="report.id"
                    :href="`${base}/${report.id}`"
                    class="hover:bg-muted/50 grid gap-3 p-4 md:grid-cols-[140px_1fr_120px_auto] md:items-center"
                >
                    <p class="text-sm">{{ report.report_date }}</p>
                    <p class="truncate font-medium">{{ report.responsible_name || 'პასუხისმგებელი მიუთითებელია' }}</p>
                    <p class="text-muted-foreground text-sm">
                        {{ report.headcount_manual_override ?? report.headcount_from_attendance ?? '—' }} კაცი
                    </p>
                    <StatusBadge :label="STATUS_LABEL[report.status] || report.status" :tone="STATUS_TONE[report.status] || 'neutral'" />
                </Link>
            </div>
        </div>

        <div v-if="lastPage > 1" class="flex items-center gap-2">
            <Button variant="outline" size="sm" :disabled="meta.page <= 1" @click="goToPage(meta.page - 1)">← წინა</Button>
            <span class="text-muted-foreground text-sm">გვერდი {{ meta.page }} / {{ lastPage }}</span>
            <Button variant="outline" size="sm" :disabled="meta.page >= lastPage" @click="goToPage(meta.page + 1)">შემდეგი →</Button>
        </div>
    </div>
</template>
