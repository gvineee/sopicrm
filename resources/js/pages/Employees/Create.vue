<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmployeeForm from '@/components/Employees/EmployeeForm.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'თანამშრომლები', href: '/employees' },
            { title: 'დამატება', href: '/employees/create' },
        ],
        mobileTitle: 'თანამშრომლის დამატება',
    },
});

defineProps<{
    teams: Array<{ id: string; name: string }>;
    supervisors: Array<{ id: string; first_name: string; last_name: string }>;
    positions: Array<{ id: string; name: string }>;
}>();
</script>

<template>
    <Head title="თანამშრომლის დამატება" />
    <div class="mx-auto w-full max-w-5xl p-4 md:p-6">
        <div class="mb-6">
            <h1 class="text-2xl font-semibold">ახალი თანამშრომელი</h1>
            <p class="text-muted-foreground text-sm">
                შექმენით პროფილი და საწყისი დასაქმების ჩანაწერი.
            </p>
        </div>
        <div class="border-border bg-card rounded-xl border p-5 md:p-6">
            <EmployeeForm
                mode="create"
                :teams="teams"
                :supervisors="supervisors"
                :positions="positions"
            />
        </div>
    </div>
</template>
