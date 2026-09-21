<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmptyState from '@/components/states/EmptyState.vue';

type Employee = { id: string; first_name: string; last_name: string };
type ShiftTemplate = { id: string; name: string };
type ShiftAssignment = {
    id: string;
    employee_name: string | null;
    shift_template_name: string | null;
    effective_from: string;
    effective_to: string | null;
};

defineOptions({ layout: { mobileTitle: 'ცვლაზე მინიჭებები' } });

const props = defineProps<{
    shiftAssignments: ShiftAssignment[];
    employees: Employee[];
    shiftTemplates: ShiftTemplate[];
    canManage: boolean;
}>();

const form = useForm({
    employee_id: '',
    shift_template_id: '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_to: '',
});

function submit() {
    form.transform((data) => ({ ...data, effective_to: data.effective_to || null })).post('/attendance/shift-assignments', {
        preserveScroll: true,
        onSuccess: () => form.reset('effective_to'),
    });
}

function endAssignment(assignment: ShiftAssignment) {
    const effectiveTo = prompt(`ცვლაზე მინიჭების დასრულების თარიღი (${assignment.employee_name}):`, new Date().toISOString().slice(0, 10));
    if (!effectiveTo) return;
    useForm({ effective_to: effectiveTo }).post(`/attendance/shift-assignments/${assignment.id}/end`, { preserveScroll: true });
}
</script>

<template>
    <Head title="ცვლაზე მინიჭებები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">ცვლაზე მინიჭებები</h1>
            <p class="text-muted-foreground text-sm">თანამშრომლების მიბმა ცვლის შაბლონებზე ვადებით.</p>
        </div>

        <form v-if="canManage" class="border-border bg-card grid gap-4 rounded-xl border p-5 sm:grid-cols-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="assign-employee">თანამშრომელი</Label>
                <select id="assign-employee" v-model="form.employee_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                    <option value="" disabled>აირჩიეთ</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.first_name }} {{ e.last_name }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="assign-template">ცვლის შაბლონი</Label>
                <select id="assign-template" v-model="form.shift_template_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                    <option value="" disabled>აირჩიეთ</option>
                    <option v-for="t in shiftTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="assign-from">დაწყების თარიღი</Label>
                <Input id="assign-from" v-model="form.effective_from" type="date" required />
            </div>
            <div class="flex items-end">
                <Button type="submit" :disabled="form.processing" class="w-full">მინიჭება</Button>
            </div>
        </form>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="shiftAssignments.length === 0" title="მინიჭება არ არის დამატებული" description="მიანიჭეთ პირველი თანამშრომელი ცვლის შაბლონს." />
            <div v-else class="divide-border divide-y">
                <div
                    v-for="assignment in shiftAssignments"
                    :key="assignment.id"
                    class="grid gap-3 p-4 md:grid-cols-[1fr_1fr_140px_140px_auto] md:items-center"
                >
                    <p class="truncate font-medium">{{ assignment.employee_name }}</p>
                    <p class="text-muted-foreground truncate text-sm">{{ assignment.shift_template_name }}</p>
                    <p class="text-muted-foreground text-sm">{{ assignment.effective_from }}</p>
                    <p class="text-muted-foreground text-sm">{{ assignment.effective_to || 'მიმდინარე' }}</p>
                    <button
                        v-if="canManage && !assignment.effective_to"
                        type="button"
                        class="text-destructive text-sm hover:underline"
                        @click="endAssignment(assignment)"
                    >
                        დასრულება
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
