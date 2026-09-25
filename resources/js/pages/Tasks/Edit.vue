<script setup lang="ts">
/**
 * Audit A08: this form used to edit scalar fields only, while the create form
 * offered additional performers, brigades, dependencies and a checklist — so
 * anything entered at creation could never afterwards be corrected. The three
 * collections are managed here now, with the same controls the create form
 * uses.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ChecklistItem = {
    id: string;
    label: string;
    is_required: boolean;
    is_checked: boolean;
};

type Assignee = {
    id: string;
    employee_id?: string | null;
    team_id?: string | null;
};

type Dependency = {
    id: string;
    depends_on_task_id: string;
};

type TaskDetail = {
    id: string;
    title: string;
    description?: string | null;
    accountable_owner?: { id: string; full_name: string } | null;
    priority: string;
    due_at?: string | null;
    planned_duration_minutes?: number | null;
    unit?: string | null;
    planned_quantity?: string | null;
    requires_photo_evidence: boolean;
    min_required_photos: number;
    assignees?: Assignee[];
    checklist_items?: ChecklistItem[];
    dependencies?: Dependency[];
};

const props = defineProps<{
    project: { id: string; name: string };
    task: TaskDetail;
    employees: Array<{ id: string; full_name: string }>;
    teams: Array<{ id: string; name: string }>;
    existingTasks: Array<{ id: string; title: string }>;
}>();

defineOptions({ layout: { mobileTitle: 'დავალების რედაქტირება' } });

// A new row carries no id; the server reads that as "create". An existing row
// keeps its id so that correcting a label does not throw away who ticked the
// item and when.
type ChecklistRow = { id: string | null; label: string; is_required: boolean; is_checked: boolean };

const form = useForm({
    title: props.task.title,
    description: props.task.description ?? '',
    accountable_owner_employee_id: props.task.accountable_owner?.id ?? '',
    priority: props.task.priority,
    due_at: props.task.due_at ? props.task.due_at.slice(0, 16) : '',
    planned_duration_minutes: props.task.planned_duration_minutes ?? '',
    unit: props.task.unit ?? '',
    planned_quantity: props.task.planned_quantity ?? '',
    requires_photo_evidence: props.task.requires_photo_evidence,
    min_required_photos: props.task.min_required_photos,
    checklist_items: (props.task.checklist_items ?? []).map((item): ChecklistRow => ({
        id: item.id,
        label: item.label,
        is_required: item.is_required,
        is_checked: item.is_checked,
    })),
    assignee_employee_ids: (props.task.assignees ?? [])
        .map((a) => a.employee_id)
        .filter((id): id is string => Boolean(id)),
    assignee_team_ids: (props.task.assignees ?? [])
        .map((a) => a.team_id)
        .filter((id): id is string => Boolean(id)),
    depends_on_task_ids: (props.task.dependencies ?? []).map((d) => d.depends_on_task_id),
});

const removedCheckedLabels = ref<string[]>([]);

function addChecklistItem() {
    form.checklist_items.push({ id: null, label: '', is_required: true, is_checked: false });
}

function removeChecklistItem(index: number) {
    const [removed] = form.checklist_items.splice(index, 1);

    // Removing an item someone already confirmed destroys that confirmation.
    // It is allowed — the task is still open — but it should not happen
    // silently, so the form says so until it is saved or the page reloaded.
    if (removed?.is_checked && removed.label) {
        removedCheckedLabels.value.push(removed.label);
    }
}

function fieldError(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            description: data.description || null,
            due_at: data.due_at || null,
            planned_duration_minutes: data.planned_duration_minutes || null,
            unit: data.unit || null,
            planned_quantity: data.planned_quantity || null,
            checklist_items: data.checklist_items
                .filter((item) => item.label.trim() !== '')
                .map((item) => ({ id: item.id, label: item.label, is_required: item.is_required })),
        }))
        .put(`/projects/${props.project.id}/tasks/${props.task.id}`);
}
</script>

<template>
    <Head title="დავალების რედაქტირება" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link :href="`/projects/${project.id}/tasks/${task.id}`" class="text-muted-foreground text-sm hover:underline">← დავალება</Link>
            <h1 class="mt-2 text-2xl font-semibold">დავალების რედაქტირება</h1>
        </div>

        <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
            <p v-if="fieldError('status')" class="border-warning bg-warning/10 rounded-lg border p-3 text-sm">{{ fieldError('status') }}</p>

            <div class="grid gap-2">
                <Label>სათაური</Label>
                <Input v-model="form.title" required />
                <p v-if="fieldError('title')" class="text-destructive text-xs">{{ fieldError('title') }}</p>
            </div>
            <div class="grid gap-2">
                <Label>აღწერა</Label>
                <textarea v-model="form.description" rows="3" class="border-input bg-background rounded-md border px-3 py-2 text-sm" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>პასუხისმგებელი</Label>
                    <select v-model="form.accountable_owner_employee_id" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.full_name }}</option>
                    </select>
                    <p v-if="fieldError('accountable_owner_employee_id')" class="text-destructive text-xs">{{ fieldError('accountable_owner_employee_id') }}</p>
                </div>
                <div class="grid gap-2">
                    <Label>პრიორიტეტი</Label>
                    <select v-model="form.priority" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="low">დაბალი</option>
                        <option value="normal">ჩვეულებრივი</option>
                        <option value="high">მაღალი</option>
                        <option value="urgent">გადაუდებელი</option>
                    </select>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2"><Label>ვადა</Label><Input v-model="form.due_at" type="datetime-local" /></div>
                <div class="grid gap-2"><Label>ხანგრძლივობა (წთ)</Label><Input v-model="form.planned_duration_minutes" type="number" min="1" /></div>
                <div class="grid gap-2"><Label>ერთეული</Label><Input v-model="form.unit" /></div>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 sm:gap-4">
                <div class="grid gap-2"><Label>დაგეგმილი მოცულობა</Label><Input v-model="form.planned_quantity" type="number" min="0" step="0.01" /></div>
                <div class="grid gap-2"><Label>საჭირო მინიმალური ფოტოები</Label><Input v-model="form.min_required_photos" type="number" min="0" /></div>
            </div>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2"><input v-model="form.requires_photo_evidence" type="checkbox" /> ფოტო სავალდებულოა</label>
            </div>

            <div class="border-border grid gap-2 border-t pt-4">
                <Label>დამატებითი შემსრულებლები</Label>
                <div class="grid gap-1 sm:grid-cols-2">
                    <label v-for="employee in employees" :key="employee.id" class="flex items-center gap-2 text-sm">
                        <input v-model="form.assignee_employee_ids" type="checkbox" :value="employee.id" />
                        {{ employee.full_name }}
                    </label>
                </div>
            </div>

            <div v-if="teams.length" class="grid gap-2">
                <Label>ბრიგადები</Label>
                <div class="grid gap-1 sm:grid-cols-2">
                    <label v-for="team in teams" :key="team.id" class="flex items-center gap-2 text-sm">
                        <input v-model="form.assignee_team_ids" type="checkbox" :value="team.id" />
                        {{ team.name }}
                    </label>
                </div>
            </div>

            <div v-if="existingTasks.length" class="grid gap-2">
                <Label>დამოკიდებულია დავალებებზე</Label>
                <div class="grid gap-1">
                    <label v-for="other in existingTasks" :key="other.id" class="flex items-center gap-2 text-sm">
                        <input v-model="form.depends_on_task_ids" type="checkbox" :value="other.id" />
                        {{ other.title }}
                    </label>
                </div>
                <p v-if="fieldError('depends_on_task_id')" class="text-destructive text-xs">{{ fieldError('depends_on_task_id') }}</p>
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label>Checklist</Label>
                    <Button type="button" variant="outline" size="sm" @click="addChecklistItem">დამატება</Button>
                </div>
                <p v-if="removedCheckedLabels.length" class="border-warning bg-warning/10 rounded-lg border p-3 text-xs">
                    შენახვის შემდეგ წაიშლება უკვე მონიშნული პუნქტ(ებ)ი და მათი დადასტურების ჩანაწერი:
                    {{ removedCheckedLabels.join(', ') }}
                </p>
                <div v-for="(item, index) in form.checklist_items" :key="item.id ?? `new-${index}`" class="flex flex-wrap items-center gap-2">
                    <Input v-model="item.label" class="min-w-48 flex-1" placeholder="პუნქტის დასახელება" />
                    <label class="flex items-center gap-2 text-sm"><input v-model="item.is_required" type="checkbox" /> სავალდებულო</label>
                    <span v-if="item.is_checked" class="text-muted-foreground text-xs">მონიშნულია</span>
                    <Button type="button" variant="ghost" size="sm" @click="removeChecklistItem(index)">წაშლა</Button>
                </div>
            </div>

            <Button type="submit" :disabled="form.processing">შენახვა</Button>
        </form>
    </div>
</template>
