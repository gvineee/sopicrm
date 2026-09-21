<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '@/components/states/EmptyState.vue';

type Revision = {
    id: string;
    snapshot: Record<string, unknown>;
    revised_by_name: string | null;
    revised_at: string | null;
    reason: string | null;
};

const props = defineProps<{
    project: { id: string; name: string };
    report: { id: string; report_date: string };
    revisions: Revision[];
}>();

defineOptions({ layout: { mobileTitle: 'ცვლილებების ისტორია' } });

const base = `/projects/${props.project.id}/daily-journal`;
</script>

<template>
    <Head :title="`ცვლილებების ისტორია — ${report.report_date}`" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link :href="`${base}/${report.id}`" class="text-muted-foreground text-sm hover:underline">← ჩანაწერს</Link>
            <h1 class="mt-1 text-2xl font-semibold">ცვლილებების ისტორია — {{ report.report_date }}</h1>
        </div>

        <EmptyState v-if="revisions.length === 0" title="ცვლილება არ არის" description="ეს ჩანაწერი მიღების შემდეგ არ შესწორებულა." />
        <div v-else class="flex flex-col gap-3">
            <div v-for="revision in revisions" :key="revision.id" class="border-border bg-card rounded-xl border p-4">
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="font-medium">{{ revision.revised_by_name || '—' }}</span>
                    <span class="text-muted-foreground">{{ revision.revised_at ? new Date(revision.revised_at).toLocaleString('ka-GE') : '—' }}</span>
                </div>
                <p v-if="revision.reason" class="text-muted-foreground mt-2 text-sm">მიზეზი: {{ revision.reason }}</p>
                <details class="mt-2 text-sm">
                    <summary class="text-muted-foreground cursor-pointer">ცვლილებამდელი მდგომარეობა</summary>
                    <pre class="bg-muted/50 mt-2 overflow-x-auto rounded-lg p-3 text-xs">{{ JSON.stringify(revision.snapshot, null, 2) }}</pre>
                </details>
            </div>
        </div>
    </div>
</template>
