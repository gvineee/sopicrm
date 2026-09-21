<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Company = {
    id: string;
    name: string;
    legal_name: string | null;
    code: string | null;
    default_currency: string;
    default_timezone: string;
    is_active: boolean;
};

const props = defineProps<{ company?: Company }>();

const form = useForm({
    name: props.company?.name ?? '',
    legal_name: props.company?.legal_name ?? '',
    code: props.company?.code ?? '',
    default_currency: props.company?.default_currency ?? 'GEL',
    default_timezone: props.company?.default_timezone ?? 'Asia/Tbilisi',
    is_active: props.company?.is_active ?? true,
});

function submit() {
    const options = { preserveScroll: true };

    if (props.company) {
        form.put(`/companies/${props.company.id}`, options);
        return;
    }

    form.post('/companies', options);
}
</script>

<template>
    <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="company-name">სახელი</Label>
            <Input id="company-name" v-model="form.name" required maxlength="255" />
            <p v-if="form.errors.name" class="text-destructive text-sm">{{ form.errors.name }}</p>
        </div>

        <div class="grid gap-2">
            <Label for="company-legal-name">იურიდიული დასახელება</Label>
            <Input id="company-legal-name" v-model="form.legal_name" maxlength="255" />
            <p v-if="form.errors.legal_name" class="text-destructive text-sm">{{ form.errors.legal_name }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="company-code">კოდი</Label>
                <Input id="company-code" v-model="form.code" maxlength="64" placeholder="მაგ. ODA-GE" />
                <p v-if="form.errors.code" class="text-destructive text-sm">{{ form.errors.code }}</p>
            </div>
            <div class="grid gap-2">
                <Label for="company-currency">ვალუტა</Label>
                <Input id="company-currency" v-model="form.default_currency" required maxlength="3" />
                <p v-if="form.errors.default_currency" class="text-destructive text-sm">
                    {{ form.errors.default_currency }}
                </p>
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="company-timezone">დროის სარტყელი</Label>
            <Input id="company-timezone" v-model="form.default_timezone" required />
            <p v-if="form.errors.default_timezone" class="text-destructive text-sm">
                {{ form.errors.default_timezone }}
            </p>
        </div>

        <label class="border-border flex cursor-pointer items-center gap-3 rounded-lg border p-3">
            <input v-model="form.is_active" type="checkbox" class="size-4" />
            <span>
                <span class="block text-sm font-medium">აქტიური კომპანია</span>
                <span class="text-muted-foreground block text-xs">გათიშვა ისტორიას არ წაშლის.</span>
            </span>
        </label>

        <Button type="submit" :disabled="form.processing">
            {{ company ? 'ცვლილებების შენახვა' : 'კომპანიის დამატება' }}
        </Button>
    </form>
</template>
