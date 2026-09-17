<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    token: string;
    valid: boolean;
    employeeName?: string | null;
}>();
defineOptions({
    layout: {
        title: 'ანგარიშის შექმნა',
        description: 'დაასრულეთ თანამშრომლის ანგარიშის აქტივაცია',
    },
});

const form = useForm({
    name: props.employeeName ?? '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
});
</script>

<template>
    <Head title="ანგარიშის შექმნა" />
    <div v-if="!valid" class="text-center">
        <h1 class="text-lg font-semibold">ბმული არასწორია ან ვადაგასულია</h1>
        <p class="text-muted-foreground mt-2 text-sm">
            სთხოვეთ HR-ს ახალი მოწვევის შექმნა.
        </p>
    </div>
    <form
        v-else
        class="grid gap-5"
        @submit.prevent="form.post(`/employee-invites/${token}`)"
    >
        <div class="grid gap-2">
            <Label for="name">სახელი და გვარი</Label
            ><Input id="name" v-model="form.name" required /><InputError
                :message="form.errors.name"
            />
        </div>
        <div class="grid gap-2">
            <Label for="email">ელფოსტა</Label
            ><Input
                id="email"
                v-model="form.email"
                type="email"
                required
            /><InputError :message="form.errors.email" />
        </div>
        <div class="grid gap-2">
            <Label for="phone">ტელეფონი</Label
            ><Input id="phone" v-model="form.phone" type="tel" /><InputError
                :message="form.errors.phone"
            />
        </div>
        <div class="grid gap-2">
            <Label for="password">პაროლი</Label
            ><Input
                id="password"
                v-model="form.password"
                type="password"
                required
            /><InputError :message="form.errors.password" />
        </div>
        <div class="grid gap-2">
            <Label for="password_confirmation">გაიმეორეთ პაროლი</Label
            ><Input
                id="password_confirmation"
                v-model="form.password_confirmation"
                type="password"
                required
            />
        </div>
        <Button type="submit" :disabled="form.processing"
            >ანგარიშის შექმნა</Button
        >
    </form>
</template>
