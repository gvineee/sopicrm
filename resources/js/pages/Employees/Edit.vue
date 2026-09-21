<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmployeeForm from '@/components/Employees/EmployeeForm.vue';

type Employee = {
    id: string;
    internal_code: string;
    first_name: string;
    last_name: string;
    phone?: string | null;
    position?: string | null;
    position_id?: string | null;
    job_position?: { id: string; name: string } | null;
    profession_skills?: string[];
    team?: { id: string } | null;
    supervisor?: { id: string } | null;
    emergency_contact_name?: string | null;
    emergency_contact_phone?: string | null;
    personal_id_number?: string | null;
};

const props = defineProps<{
    employee: Employee;
    teams: Array<{ id: string; name: string }>;
    supervisors: Array<{ id: string; first_name: string; last_name: string }>;
    positions: Array<{ id: string; name: string }>;
}>();

defineOptions({ layout: { mobileTitle: 'პროფილის რედაქტირება' } });
</script>

<template>
    <Head
        :title="`${employee.first_name} ${employee.last_name} — რედაქტირება`"
    />
    <div class="mx-auto w-full max-w-5xl p-4 md:p-6">
        <div class="mb-6">
            <a
                href="/employees"
                class="text-muted-foreground text-sm hover:underline"
                >← თანამშრომლები</a
            >
            <h1 class="mt-2 text-2xl font-semibold">პროფილის რედაქტირება</h1>
        </div>
        <div class="border-border bg-card rounded-xl border p-5 md:p-6">
            <EmployeeForm
                mode="edit"
                :employee="props.employee"
                :teams="teams"
                :supervisors="supervisors"
                :positions="positions"
            />
        </div>
    </div>
</template>
