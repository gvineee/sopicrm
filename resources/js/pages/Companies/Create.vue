<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CompanyForm from '@/components/Companies/CompanyForm.vue';

defineOptions({ layout: { mobileTitle: 'კომპანიის დამატება' } });
</script>

<template>
    <Head title="კომპანიის დამატება" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/companies" class="text-muted-foreground text-sm hover:underline">← კომპანიები</Link>
            <h1 class="mt-2 text-2xl font-semibold">კომპანიის დამატება</h1>
        </div>
        <CompanyForm />
    </div>
</template>
