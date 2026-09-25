<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({ layout: { mobileTitle: 'ორგანიზაციის დამატება' } });

const form = useForm({
    name: '',
    legal_name: '',
    default_currency: 'GEL',
    default_timezone: 'Asia/Tbilisi',
    owner_name: '',
    owner_email: '',
    owner_password: '',
    owner_password_confirmation: '',
});

function submit() {
    form.post('/platform/organizations', {
        preserveScroll: true,
        onFinish: () => form.reset('owner_password', 'owner_password_confirmation'),
    });
}
</script>

<template>
    <Head title="ორგანიზაციის დამატება" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/platform/organizations" class="text-muted-foreground text-sm hover:underline">← ორგანიზაციები</Link>
            <h1 class="mt-2 text-2xl font-semibold">ორგანიზაციის დამატება</h1>
            <p class="text-muted-foreground text-sm">
                ორგანიზაცია იქმნება ნაგულისხმევი კომპანიით და პირველი მომხმარებლით, რომელსაც მფლობელის როლი ენიჭება.
            </p>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <section class="border-border bg-card grid gap-4 rounded-xl border p-5">
                <h2 class="text-sm font-semibold">ორგანიზაცია</h2>
                <div class="grid gap-2">
                    <Label for="org-name">დასახელება</Label>
                    <Input id="org-name" v-model="form.name" required maxlength="255" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="org-legal-name">იურიდიული დასახელება</Label>
                    <Input id="org-legal-name" v-model="form.legal_name" maxlength="255" />
                    <InputError :message="form.errors.legal_name" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="org-currency">ვალუტა</Label>
                        <Input id="org-currency" v-model="form.default_currency" maxlength="3" />
                        <InputError :message="form.errors.default_currency" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="org-timezone">დროის სარტყელი</Label>
                        <Input id="org-timezone" v-model="form.default_timezone" />
                        <InputError :message="form.errors.default_timezone" />
                    </div>
                </div>
            </section>

            <section class="border-border bg-card grid gap-4 rounded-xl border p-5">
                <h2 class="text-sm font-semibold">პირველი მომხმარებელი (მფლობელი)</h2>
                <div class="grid gap-2">
                    <Label for="owner-name">სახელი და გვარი</Label>
                    <Input id="owner-name" v-model="form.owner_name" required maxlength="255" autocomplete="off" />
                    <InputError :message="form.errors.owner_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="owner-email">ელფოსტა</Label>
                    <Input id="owner-email" v-model="form.owner_email" type="email" required autocomplete="off" />
                    <InputError :message="form.errors.owner_email" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="owner-password">პაროლი</Label>
                        <Input id="owner-password" v-model="form.owner_password" type="password" required autocomplete="new-password" />
                        <InputError :message="form.errors.owner_password" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="owner-password-confirmation">გაიმეორეთ პაროლი</Label>
                        <Input
                            id="owner-password-confirmation"
                            v-model="form.owner_password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                        />
                    </div>
                </div>
            </section>

            <Button type="submit" class="w-fit" :disabled="form.processing">ორგანიზაციის შექმნა</Button>
        </form>
    </div>
</template>
