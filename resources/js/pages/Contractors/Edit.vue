<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ContractorForm from '@/components/Contractors/ContractorForm.vue';

type Contractor = {
    id: string;
    name: string;
    legal_name: string | null;
    tax_id: string | null;
    contact_person: string | null;
    phone: string | null;
    email: string | null;
    default_currency: string;
    is_active: boolean;
    notes: string | null;
};

defineOptions({ layout: { mobileTitle: 'კონტრაქტორის რედაქტირება' } });
defineProps<{ contractor: Contractor }>();
</script>

<template>
    <Head :title="contractor.name" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link :href="`/contractors/${contractor.id}`" class="text-muted-foreground text-sm hover:underline">← {{ contractor.name }}</Link>
            <h1 class="mt-2 text-2xl font-semibold">კონტრაქტორის რედაქტირება</h1>
        </div>
        <ContractorForm :contractor="contractor" />
    </div>
</template>
