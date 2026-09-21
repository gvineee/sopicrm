<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
    self_close_allowed: boolean;
    requires_photo_evidence: boolean;
    min_required_photos: number;
};

const props = defineProps<{
    project: { id: string; name: string };
    task: TaskDetail;
    employees: Array<{ id: string; full_name: string }>;
}>();

defineOptions({ layout: { mobileTitle: 'დავალების რედაქტირება' } });

const form = useForm({
    title: props.task.title,
    description: props.task.description ?? '',
    accountable_owner_employee_id: props.task.accountable_owner?.id ?? '',
    priority: props.task.priority,
    due_at: props.task.due_at ? props.task.due_at.slice(0, 16) : '',
    planned_duration_minutes: props.task.planned_duration_minutes ?? '',
    unit: props.task.unit ?? '',
    planned_quantity: props.task.planned_quantity ?? '',
    self_close_allowed: props.task.self_close_allowed,
    requires_photo_evidence: props.task.requires_photo_evidence,
    min_required_photos: props.task.min_required_photos,
});

function submit() {
    form
        .transform((data) => ({
            ...data,
            description: data.description || null,
            due_at: data.due_at || null,
            planned_duration_minutes: data.planned_duration_minutes || null,
            unit: data.unit || null,
            planned_quantity: data.planned_quantity || null,
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
            <p v-if="(form.errors as Record<string, string>).status" class="border-warning bg-warning/10 rounded-lg border p-3 text-sm">{{ (form.errors as Record<string, string>).status }}</p>
            <div class="grid gap-2">
                <Label>სათაური</Label>
                <Input v-model="form.title" required />
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
                <label class="flex items-center gap-2"><input v-model="form.self_close_allowed" type="checkbox" /> თვითდახურვა დაშვებულია</label>
            </div>
            <Button type="submit" :disabled="form.processing">შენახვა</Button>
        </form>
    </div>
</template>
