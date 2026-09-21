<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StatusBadge from '@/components/StatusBadge.vue';
import type { StatusTone } from '@/types';

type LinkedTask = {
    task_id: string;
    title: string | null;
    unit: string | null;
    accepted_quantity: string | null;
    planned_quantity: string | null;
    note: string | null;
};

type ReportDetail = {
    id: string;
    report_date: string;
    status: string;
    version: number;
    responsible_name: string | null;
    team_ids: string[];
    headcount_from_attendance: number | null;
    headcount_manual_override: number | null;
    headcount_variance_note: string | null;
    work_performed_note: string | null;
    equipment_used: string[];
    materials_received_note: string | null;
    delays_note: string | null;
    quality_safety_note: string | null;
    next_day_plan: string | null;
    weather_manual: string | null;
    submitted_at: string | null;
    submitted_by_name: string | null;
    accepted_at: string | null;
    accepted_by_name: string | null;
    linked_tasks: LinkedTask[];
    revision_count: number;
};

const props = defineProps<{
    project: { id: string; name: string; code: string | null };
    report: ReportDetail;
    teams: { id: string; name: string }[];
    can: { update: boolean; submit: boolean; accept: boolean; return: boolean };
}>();

defineOptions({ layout: { mobileTitle: 'დღიური ჩანაწერი' } });

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

const teamNames = computed(() => props.teams.filter((t) => props.report.team_ids.includes(t.id)).map((t) => t.name));

const showAccept = reactive({ open: false });
const showReturn = reactive({ open: false });

const submitForm = useForm({ target_version: props.report.version });
const acceptForm = useForm({ target_version: props.report.version, notes: '' });
const returnForm = useForm({ target_version: props.report.version, reason: '' });

function submitReport() {
    submitForm.post(`${base}/${props.report.id}/submit`, { preserveScroll: true });
}

function acceptReport() {
    acceptForm.post(`${base}/${props.report.id}/accept`, {
        preserveScroll: true,
        onSuccess: () => {
            showAccept.open = false;
        },
    });
}

function returnReport() {
    returnForm.post(`${base}/${props.report.id}/return`, {
        preserveScroll: true,
        onSuccess: () => {
            showReturn.open = false;
        },
    });
}

// The server keys a stale-optimistic-concurrency error as 'version'
// (App\Http\Controllers\DailyJournal\DailyReportController's own catch
// blocks), which doesn't match any of these forms' submitted field name
// ('target_version') — useForm()'s error type is inferred strictly from the
// data object, so this key needs an untyped read.
function versionError(errors: Record<string, string | undefined>): string | undefined {
    return errors.version;
}
</script>

<template>
    <Head :title="`დღიური ჩანაწერი — ${report.report_date}`" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link :href="base" class="text-muted-foreground text-sm hover:underline">← დღიური ჟურნალი</Link>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ report.report_date }}</h1>
                    <StatusBadge :label="STATUS_LABEL[report.status] || report.status" :tone="STATUS_TONE[report.status] || 'neutral'" />
                </div>
                <p class="text-muted-foreground text-sm">{{ project.name }} · {{ report.responsible_name || 'პასუხისმგებელი მიუთითებელია' }}</p>
            </div>
            <Button v-if="can.update" as-child variant="outline">
                <Link :href="`${base}/${report.id}/edit`">რედაქტირება</Link>
            </Button>
        </div>

        <div v-if="report.submitted_at" class="border-border bg-muted/30 rounded-xl border p-3 text-sm">
            წარდგენილია {{ report.submitted_by_name || '—' }}-ის მიერ, {{ new Date(report.submitted_at).toLocaleString('ka-GE') }}
        </div>
        <div v-if="report.accepted_at" class="rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-950 dark:bg-emerald-950/30 dark:text-emerald-100">
            მიღებულია {{ report.accepted_by_name || '—' }}-ის მიერ, {{ new Date(report.accepted_at).toLocaleString('ka-GE') }}
        </div>

        <div class="flex flex-wrap gap-2">
            <Button v-if="can.submit" size="sm" :disabled="submitForm.processing" @click="submitReport">წარდგენა მისაღებად</Button>
            <Button v-if="can.accept" size="sm" @click="showAccept.open = !showAccept.open">მიღება</Button>
            <Button v-if="can.return" size="sm" variant="outline" @click="showReturn.open = !showReturn.open">დაბრუნება</Button>
        </div>
        <p v-if="versionError(submitForm.errors)" class="text-destructive text-sm">{{ versionError(submitForm.errors) }}</p>

        <form
            v-if="showAccept.open"
            class="border-border bg-card grid gap-2 rounded-xl border p-4"
            @submit.prevent="acceptReport"
        >
            <Label for="accept-notes">შენიშვნა (არასავალდებულო)</Label>
            <Input id="accept-notes" v-model="acceptForm.notes" maxlength="2000" />
            <p v-if="versionError(acceptForm.errors)" class="text-destructive text-sm">{{ versionError(acceptForm.errors) }}</p>
            <Button type="submit" size="sm" class="w-fit" :disabled="acceptForm.processing">დადასტურება</Button>
        </form>

        <form
            v-if="showReturn.open"
            class="border-border grid gap-2 rounded-xl border border-amber-300 p-4"
            @submit.prevent="returnReport"
        >
            <Label for="return-reason">დაბრუნების მიზეზი</Label>
            <Input id="return-reason" v-model="returnForm.reason" required maxlength="2000" placeholder="რა უნდა შესწორდეს" />
            <p v-if="returnForm.errors.reason" class="text-destructive text-sm">{{ returnForm.errors.reason }}</p>
            <p v-if="versionError(returnForm.errors)" class="text-destructive text-sm">{{ versionError(returnForm.errors) }}</p>
            <Button type="submit" size="sm" variant="outline" class="w-fit" :disabled="returnForm.processing">დაბრუნება</Button>
        </form>

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">კაცების რაოდენობა</h2>
                <p class="text-muted-foreground mt-2 text-sm">
                    დასწრებიდან: {{ report.headcount_from_attendance ?? '—' }} · ხელით: {{ report.headcount_manual_override ?? '—' }}
                </p>
                <p v-if="report.headcount_variance_note" class="text-muted-foreground mt-1 text-sm">{{ report.headcount_variance_note }}</p>
                <p v-if="teamNames.length" class="mt-2 text-sm">ბრიგადები: {{ teamNames.join(', ') }}</p>
            </section>

            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">ამინდი</h2>
                <p class="text-muted-foreground mt-2 text-sm">{{ report.weather_manual || '—' }}</p>
            </section>

            <section class="border-border bg-card rounded-xl border p-5 lg:col-span-2">
                <h2 class="font-semibold">შესრულებული სამუშაო</h2>
                <p class="text-muted-foreground mt-2 text-sm whitespace-pre-line">{{ report.work_performed_note || '—' }}</p>
            </section>

            <section v-if="report.equipment_used.length" class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">გამოყენებული ტექნიკა</h2>
                <ul class="mt-2 list-inside list-disc text-sm">
                    <li v-for="(item, i) in report.equipment_used" :key="i">{{ item }}</li>
                </ul>
            </section>

            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">მიღებული მასალა</h2>
                <p class="text-muted-foreground mt-2 text-sm whitespace-pre-line">{{ report.materials_received_note || '—' }}</p>
            </section>

            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">შეფერხებები</h2>
                <p class="text-muted-foreground mt-2 text-sm whitespace-pre-line">{{ report.delays_note || '—' }}</p>
            </section>

            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">ხარისხი/უსაფრთხოება</h2>
                <p class="text-muted-foreground mt-2 text-sm whitespace-pre-line">{{ report.quality_safety_note || '—' }}</p>
            </section>

            <section class="border-border bg-card rounded-xl border p-5 lg:col-span-2">
                <h2 class="font-semibold">მომდევნო დღის გეგმა</h2>
                <p class="text-muted-foreground mt-2 text-sm whitespace-pre-line">{{ report.next_day_plan || '—' }}</p>
            </section>
        </div>

        <section v-if="report.linked_tasks.length" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">დაკავშირებული დავალებები</h2>
            <div class="divide-border mt-3 divide-y">
                <div v-for="task in report.linked_tasks" :key="task.task_id" class="flex items-center justify-between gap-3 py-2 text-sm">
                    <span>{{ task.title }}</span>
                    <span class="text-muted-foreground">{{ task.accepted_quantity ?? 0 }} / {{ task.planned_quantity ?? '—' }} {{ task.unit || '' }}</span>
                </div>
            </div>
        </section>

        <div v-if="report.revision_count > 0">
            <Link :href="`${base}/${report.id}/revisions`" class="text-sm hover:underline">
                ისტორია ({{ report.revision_count }} ცვლილება) →
            </Link>
        </div>
    </div>
</template>
