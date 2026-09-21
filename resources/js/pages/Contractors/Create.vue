<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ContractorForm from '@/components/Contractors/ContractorForm.vue';

defineOptions({ layout: { mobileTitle: 'კონტრაქტორის დამატება' } });
</script>

<template>
    <Head title="კონტრაქტორის დამატება" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/contractors" class="text-muted-foreground text-sm hover:underline">← კონტრაქტორები</Link>
            <h1 class="mt-2 text-2xl font-semibold">კონტრაქტორის დამატება</h1>
        </div>
        <ContractorForm />
    </div>
</template>
