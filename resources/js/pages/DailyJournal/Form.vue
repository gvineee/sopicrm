<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EntityPicker from '@/components/EntityPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Team = { id: string; name: string };
type TaskOption = { id: string; title: string };
type LinkedTask = { task_id: string };
type ReportDetail = {
    id: string;
    version: number;
    status: string;
    report_date: string;
    responsible_user_id: string;
    responsible_name?: string | null;
    team_ids: string[];
    headcount_manual_override: number | null;
    headcount_variance_note: string | null;
    work_performed_note: string | null;
    equipment_used: string[];
    materials_received_note: string | null;
    delays_note: string | null;
    quality_safety_note: string | null;
    next_day_plan: string | null;
    weather_manual: string | null;
    linked_tasks?: LinkedTask[];
};

const props = defineProps<{
    project: { id: string; name: string; code: string | null };
    teams: Team[];
    tasks: TaskOption[];
    report: ReportDetail | null;
}>();

defineOptions({ layout: { mobileTitle: 'დღიური ჩანაწერი' } });

const isEdit = computed(() => props.report !== null);

// The server keys some errors ('version', 'equipment_used') that don't
// match this form's own submitted field names ('target_version',
// 'equipment_used_text') — useForm()'s error type is inferred strictly from
// the data object passed to it, so those keys need an untyped read.
const serverErrors = computed(() => form.errors as Record<string, string | undefined>);
const base = `/projects/${props.project.id}/daily-journal`;

// The responsible person's NAME for display; `form.responsible_user_id`
// carries the id that is actually submitted (A12 — the id is never shown).
const responsibleName = ref<string | null>(props.report?.responsible_name ?? null);

const form = useForm({
    report_date: props.report?.report_date ?? new Date().toISOString().slice(0, 10),
    responsible_user_id: props.report?.responsible_user_id ?? '',
    team_ids: props.report?.team_ids ?? ([] as string[]),
    task_ids: props.report?.linked_tasks?.map((t) => t.task_id) ?? ([] as string[]),
    headcount_manual_override: props.report?.headcount_manual_override ?? null,
    headcount_variance_note: props.report?.headcount_variance_note ?? '',
    work_performed_note: props.report?.work_performed_note ?? '',
    equipment_used_text: (props.report?.equipment_used ?? []).join('\n'),
    materials_received_note: props.report?.materials_received_note ?? '',
    delays_note: props.report?.delays_note ?? '',
    quality_safety_note: props.report?.quality_safety_note ?? '',
    next_day_plan: props.report?.next_day_plan ?? '',
    weather_manual: props.report?.weather_manual ?? '',
    reason: '',
    target_version: props.report?.version ?? 1,
});

function toggleTeam(id: string) {
    const idx = form.team_ids.indexOf(id);
    if (idx === -1) form.team_ids.push(id);
    else form.team_ids.splice(idx, 1);
}

function toggleTask(id: string) {
    const idx = form.task_ids.indexOf(id);
    if (idx === -1) form.task_ids.push(id);
    else form.task_ids.splice(idx, 1);
}

function submit() {
    const options = { preserveScroll: true };

    form.transform((data) => ({
        ...data,
        headcount_manual_override: data.headcount_manual_override || null,
        equipment_used: data.equipment_used_text
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line.length > 0),
    }));

    if (isEdit.value && props.report) {
        form.put(`${base}/${props.report.id}`, options);
        return;
    }

    form.post(base, options);
}
</script>

<template>
    <Head :title="isEdit ? 'დღიური ჩანაწერის რედაქტირება' : 'ახალი დღიური ჩანაწერი'" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link :href="isEdit && report ? `${base}/${report.id}` : base" class="text-muted-foreground text-sm hover:underline">
                ← {{ isEdit ? 'ჩანაწერს' : 'ჟურნალს' }}
            </Link>
            <h1 class="mt-1 text-2xl font-semibold">{{ isEdit ? 'დღიური ჩანაწერის რედაქტირება' : 'ახალი დღიური ჩანაწერი' }}</h1>
            <p class="text-muted-foreground text-sm">{{ project.name }}</p>
        </div>

        <form class="border-border bg-card grid gap-5 rounded-xl border p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="report-date">თარიღი</Label>
                    <Input id="report-date" v-model="form.report_date" type="date" required />
                    <p v-if="form.errors.report_date" class="text-destructive text-sm">{{ form.errors.report_date }}</p>
                </div>
                <div class="grid gap-2">
                    <Label for="responsible">პასუხისმგებელი</Label>
                    <EntityPicker
                        id="responsible"
                        v-model="form.responsible_user_id"
                        v-model:selected-label="responsibleName"
                        :endpoint="`${base}/responsible-users`"
                        placeholder="მოძებნეთ პასუხისმგებელი"
                        empty-text="ასეთი თანამშრომელი ვერ მოიძებნა"
                    />
                    <p v-if="form.errors.responsible_user_id" class="text-destructive text-sm">{{ form.errors.responsible_user_id }}</p>
                </div>
            </div>

            <div v-if="teams.length" class="grid gap-2">
                <Label>ბრიგადები</Label>
                <div class="flex flex-wrap gap-3">
                    <label v-for="team in teams" :key="team.id" class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :checked="form.team_ids.includes(team.id)" @change="toggleTeam(team.id)" />
                        {{ team.name }}
                    </label>
                </div>
            </div>

            <div v-if="tasks.length" class="grid gap-2">
                <Label>დაკავშირებული დავალებები</Label>
                <div class="max-h-40 space-y-1 overflow-y-auto">
                    <label v-for="task in tasks" :key="task.id" class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :checked="form.task_ids.includes(task.id)" @change="toggleTask(task.id)" />
                        {{ task.title }}
                    </label>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="headcount">კაცების რაოდენობა (ხელით)</Label>
                    <Input
                        id="headcount"
                        type="number"
                        min="0"
                        :model-value="form.headcount_manual_override ?? ''"
                        @update:model-value="(v) => (form.headcount_manual_override = v === '' ? null : Number(v))"
                    />
                    <p v-if="form.errors.headcount_manual_override" class="text-destructive text-sm">
                        {{ form.errors.headcount_manual_override }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="headcount-note">განსხვავების მიზეზი</Label>
                    <Input id="headcount-note" v-model="form.headcount_variance_note" maxlength="2000" />
                    <p v-if="form.errors.headcount_variance_note" class="text-destructive text-sm">
                        {{ form.errors.headcount_variance_note }}
                    </p>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="work-note">შესრულებული სამუშაო</Label>
                <Textarea id="work-note" v-model="form.work_performed_note" rows="4" maxlength="10000" />
                <p v-if="form.errors.work_performed_note" class="text-destructive text-sm">{{ form.errors.work_performed_note }}</p>
            </div>

            <div class="grid gap-2">
                <Label for="equipment">გამოყენებული ტექნიკა</Label>
                <Textarea id="equipment" v-model="form.equipment_used_text" rows="3" placeholder="თითო ერთეული ცალკე ხაზზე" />
                <p v-if="serverErrors.equipment_used" class="text-destructive text-sm">{{ serverErrors.equipment_used }}</p>
            </div>

            <div class="grid gap-2">
                <Label for="materials">მიღებული მასალა</Label>
                <Textarea id="materials" v-model="form.materials_received_note" rows="3" maxlength="10000" />
                <p v-if="form.errors.materials_received_note" class="text-destructive text-sm">
                    {{ form.errors.materials_received_note }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="delays">შეფერხებები</Label>
                <Textarea id="delays" v-model="form.delays_note" rows="3" maxlength="10000" />
                <p v-if="form.errors.delays_note" class="text-destructive text-sm">{{ form.errors.delays_note }}</p>
            </div>

            <div class="grid gap-2">
                <Label for="quality">ხარისხი/უსაფრთხოება</Label>
                <Textarea id="quality" v-model="form.quality_safety_note" rows="3" maxlength="10000" />
                <p v-if="form.errors.quality_safety_note" class="text-destructive text-sm">{{ form.errors.quality_safety_note }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="weather">ამინდი</Label>
                    <Input id="weather" v-model="form.weather_manual" maxlength="255" />
                    <p v-if="form.errors.weather_manual" class="text-destructive text-sm">{{ form.errors.weather_manual }}</p>
                </div>
                <div class="grid gap-2">
                    <Label for="next-plan">მომდევნო დღის გეგმა</Label>
                    <Input id="next-plan" v-model="form.next_day_plan" maxlength="10000" />
                    <p v-if="form.errors.next_day_plan" class="text-destructive text-sm">{{ form.errors.next_day_plan }}</p>
                </div>
            </div>

            <div v-if="isEdit" class="grid gap-2">
                <Label for="reason">ცვლილების მიზეზი{{ report?.status === 'accepted' ? ' (სავალდებულო მიღებული დღისთვის)' : '' }}</Label>
                <Input id="reason" v-model="form.reason" maxlength="2000" :required="report?.status === 'accepted'" />
                <p v-if="form.errors.reason" class="text-destructive text-sm">{{ form.errors.reason }}</p>
            </div>
            <p v-if="serverErrors.version" class="text-destructive text-sm">{{ serverErrors.version }}</p>

            <Button type="submit" :disabled="form.processing">{{ isEdit ? 'ცვლილებების შენახვა' : 'ჩანაწერის შექმნა' }}</Button>
        </form>
    </div>
</template>
