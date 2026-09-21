<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ProjectDetail = {
    id: string;
    code: string;
    name: string;
    company_id?: string | null;
    client_id?: string | null;
    manager_user_id: string;
    address?: string | null;
    starts_on?: string | null;
    ends_on?: string | null;
    budget_baseline?: string | null;
    version: number;
};

const props = defineProps<{
    project: ProjectDetail;
    companies: Array<{ id: string; name: string; code?: string }>;
    clients: Array<{ id: string; name: string }>;
    managers: Array<{ id: string; name: string; email: string }>;
}>();

defineOptions({ layout: { mobileTitle: 'პროექტის რედაქტირება' } });

const form = useForm({
    code: props.project.code,
    name: props.project.name,
    company_id: props.project.company_id ?? '',
    client_id: props.project.client_id ?? '',
    manager_user_id: props.project.manager_user_id,
    address: props.project.address ?? '',
    starts_on: props.project.starts_on ?? '',
    ends_on: props.project.ends_on ?? '',
    budget_baseline: props.project.budget_baseline ?? '',
    version: props.project.version,
});

function submit() {
    form
        .transform((data) => ({
            ...data,
            client_id: data.client_id || null,
            company_id: data.company_id || null,
            address: data.address || null,
            starts_on: data.starts_on || null,
            ends_on: data.ends_on || null,
            budget_baseline: data.budget_baseline || null,
        }))
        .put(`/projects/${props.project.id}`);
}
</script>

<template>
    <Head title="პროექტის რედაქტირება" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link :href="`/projects/${project.id}`" class="text-muted-foreground text-sm hover:underline">← პროექტი</Link>
            <h1 class="mt-2 text-2xl font-semibold">პროექტის რედაქტირება</h1>
        </div>

        <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
            <p v-if="form.errors.version" class="border-warning bg-warning/10 rounded-lg border p-3 text-sm">
                პროექტი შეიცვალა სხვის მიერ თქვენი გახსნის შემდეგ — გთხოვთ განაახლოთ გვერდი და ხელახლა შეიტანოთ ცვლილება.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>კოდი</Label>
                    <Input v-model="form.code" required />
                    <p v-if="form.errors.code" class="text-destructive text-sm">{{ form.errors.code }}</p>
                </div>
                <div class="grid gap-2">
                    <Label>დასახელება</Label>
                    <Input v-model="form.name" required />
                </div>
            </div>

            <div class="grid gap-2">
                <Label>კომპანია</Label>
                <select v-model="form.company_id" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option value="" disabled>აირჩიეთ კომპანია</option>
                    <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}{{ company.code ? ` (${company.code})` : '' }}</option>
                </select>
                <p v-if="form.errors.company_id" class="text-destructive text-sm">{{ form.errors.company_id }}</p>
            </div>

            <div class="grid gap-2">
                <Label>კლიენტი</Label>
                <select v-model="form.client_id" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option value="">კლიენტის გარეშე</option>
                    <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label>მენეჯერი</Label>
                <select v-model="form.manager_user_id" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option v-for="manager in managers" :key="manager.id" :value="manager.id">{{ manager.name }} ({{ manager.email }})</option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label>მისამართი</Label>
                <Input v-model="form.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2"><Label>დაწყება</Label><Input v-model="form.starts_on" type="date" /></div>
                <div class="grid gap-2"><Label>დასრულება</Label><Input v-model="form.ends_on" type="date" /></div>
            </div>

            <div class="grid gap-2">
                <Label>ბიუჯეტის საწყისი მაჩვენებელი (GEL)</Label>
                <Input v-model="form.budget_baseline" type="number" min="0" step="0.01" />
            </div>

            <Button type="submit" :disabled="form.processing">შენახვა</Button>
        </form>
    </div>
</template>
