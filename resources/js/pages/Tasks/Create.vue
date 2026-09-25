<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    project: { id: string; name: string };
    employees: Array<{ id: string; full_name: string; internal_code?: string | null; has_login?: boolean }>;
    teams: Array<{ id: string; name: string }>;
    existingTasks: Array<{ id: string; title: string }>;
}>();

defineOptions({ layout: { mobileTitle: 'დავალების დამატება' } });

const form = useForm({
    title: '',
    description: '',
    accountable_owner_employee_id: '',
    priority: 'normal',
    due_at: '',
    planned_duration_minutes: '',
    unit: '',
    planned_quantity: '',
    requires_photo_evidence: true,
    min_required_photos: 1,
    checklist_items: [] as Array<{ label: string; is_required: boolean }>,
    assignee_employee_ids: [] as string[],
    assignee_team_ids: [] as string[],
    depends_on_task_ids: [] as string[],
});

function addChecklistItem() {
    form.checklist_items.push({ label: '', is_required: true });
}

function removeChecklistItem(index: number) {
    form.checklist_items.splice(index, 1);
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
            checklist_items: data.checklist_items.filter((item) => item.label.trim() !== ''),
        }))
        .post(`/projects/${props.project.id}/tasks`);
}
</script>

<template>
    <Head title="დავალების დამატება" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link :href="`/projects/${project.id}/tasks`" class="text-muted-foreground text-sm hover:underline">← დავალებები</Link>
            <h1 class="mt-2 text-2xl font-semibold">დავალების დამატება</h1>
        </div>

        <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label>სათაური</Label>
                <Input v-model="form.title" required />
                <p v-if="form.errors.title" class="text-destructive text-sm">{{ form.errors.title }}</p>
            </div>

            <div class="grid gap-2">
                <Label>აღწერა</Label>
                <textarea v-model="form.description" rows="3" class="border-input bg-background rounded-md border px-3 py-2 text-sm" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>პასუხისმგებელი (accountable owner)</Label>
                    <select v-model="form.accountable_owner_employee_id" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="" disabled>აირჩიეთ თანამშრომელი</option>
                        <option v-for="employee in employees" :key="employee.id" :value="employee.id">
                            {{ employee.full_name }}<template v-if="employee.internal_code"> · {{ employee.internal_code }}</template>
                            <!-- Audit A09: the responsible person is chosen
                                 from employees while a project member is
                                 chosen from accounts. Saying here whether this
                                 employee has a login is what connects the two
                                 lists: without one they cannot open the task,
                                 submit it, or see it in „ჩემი დღე". -->
                            <template v-if="!employee.has_login"> · ანგარიში არ აქვს</template>
                        </option>
                    </select>
                    <p v-if="form.errors.accountable_owner_employee_id" class="text-destructive text-sm">{{ form.errors.accountable_owner_employee_id }}</p>
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
                <div class="grid gap-2">
                    <Label>ერთეული</Label>
                    <!-- Audit A19: a plain text box let "20" be typed here and
                         the quantity left empty, which the detail page then
                         rendered as „0.00 / — 20". A datalist keeps units
                         configurable (the spec is explicit that they are not a
                         fixed enum) while offering the ones actually used. -->
                    <Input v-model="form.unit" list="task-units" placeholder="მ², ცალი..." />
                    <datalist id="task-units">
                        <option value="მ²" />
                        <option value="მ³" />
                        <option value="გრძ.მ" />
                        <option value="ცალი" />
                        <option value="ტ" />
                        <option value="კგ" />
                        <option value="ლ" />
                        <option value="სთ" />
                    </datalist>
                </div>
            </div>

            <div class="grid gap-2 sm:grid-cols-2 sm:gap-4">
                <div class="grid gap-2"><Label>დაგეგმილი მოცულობა</Label><Input v-model="form.planned_quantity" type="number" min="0" step="0.01" /></div>
                <div class="grid gap-2"><Label>საჭირო მინიმალური ფოტოები</Label><Input v-model="form.min_required_photos" type="number" min="0" /></div>
            </div>

            <div class="flex flex-wrap gap-4 text-sm">
                <!-- "თვითდახურვა დაშვებულია" was removed here on purpose.
                     03-Construction-Task-Manager-Spec-KA.md TM-01 cancelled
                     the self-close carve-out: acceptance now always takes two
                     different people, and nothing in the workflow reads that
                     flag any more. The column survives as history (§17), but
                     offering it as a live setting told the manager they were
                     choosing something that no longer has any effect. -->
                <label class="flex items-center gap-2"><input v-model="form.requires_photo_evidence" type="checkbox" /> ფოტო სავალდებულოა</label>
            </div>

            <div class="grid gap-2">
                <Label>დამატებითი შემსრულებლები</Label>
                <div class="flex max-h-32 flex-wrap gap-3 overflow-y-auto">
                    <label v-for="employee in employees" :key="employee.id" class="border-border flex items-center gap-2 rounded-md border px-2 py-1 text-sm">
                        <input type="checkbox" :value="employee.id" v-model="form.assignee_employee_ids" /> {{ employee.full_name }}
                    </label>
                </div>
            </div>

            <!-- Audit A08: brigades were already accepted by StoreTaskRequest
                 and created by CreateTask, but no form ever offered them, so
                 assigning work to a whole crew was unreachable from the UI. -->
            <div v-if="teams.length" class="grid gap-2">
                <Label>ბრიგადები</Label>
                <div class="flex max-h-32 flex-wrap gap-3 overflow-y-auto">
                    <label v-for="team in teams" :key="team.id" class="border-border flex items-center gap-2 rounded-md border px-2 py-1 text-sm">
                        <input v-model="form.assignee_team_ids" type="checkbox" :value="team.id" /> {{ team.name }}
                    </label>
                </div>
            </div>

            <div v-if="existingTasks.length" class="grid gap-2">
                <Label>დამოკიდებულია დავალებებზე (დამოკიდებულებები)</Label>
                <div class="flex max-h-32 flex-wrap gap-3 overflow-y-auto">
                    <label v-for="task in existingTasks" :key="task.id" class="border-border flex items-center gap-2 rounded-md border px-2 py-1 text-sm">
                        <input type="checkbox" :value="task.id" v-model="form.depends_on_task_ids" /> {{ task.title }}
                    </label>
                </div>
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label>შესამოწმებელი პუნქტები</Label>
                    <Button type="button" variant="outline" size="sm" @click="addChecklistItem">დამატება</Button>
                </div>
                <div v-for="(item, index) in form.checklist_items" :key="index" class="flex items-center gap-2">
                    <Input v-model="item.label" placeholder="პუნქტის დასახელება" class="flex-1" />
                    <label class="flex items-center gap-1 text-sm whitespace-nowrap"><input v-model="item.is_required" type="checkbox" /> სავალდებულო</label>
                    <Button type="button" variant="ghost" size="sm" @click="removeChecklistItem(index)">წაშლა</Button>
                </div>
            </div>

            <Button type="submit" :disabled="form.processing">დამატება</Button>
        </form>
    </div>
</template>
