<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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

const props = defineProps<{ contractor?: Contractor }>();

const form = useForm({
    name: props.contractor?.name ?? '',
    legal_name: props.contractor?.legal_name ?? '',
    tax_id: props.contractor?.tax_id ?? '',
    contact_person: props.contractor?.contact_person ?? '',
    phone: props.contractor?.phone ?? '',
    email: props.contractor?.email ?? '',
    default_currency: props.contractor?.default_currency ?? 'GEL',
    is_active: props.contractor?.is_active ?? true,
    notes: props.contractor?.notes ?? '',
});

function submit() {
    const options = { preserveScroll: true };

    if (props.contractor) {
        form.put(`/contractors/${props.contractor.id}`, options);
        return;
    }

    form.post('/contractors', options);
}
</script>

<template>
    <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="contractor-name">დასახელება</Label>
            <Input id="contractor-name" v-model="form.name" required maxlength="255" placeholder="მაგ. შპს ოდა-სერვისი" />
            <p v-if="form.errors.name" class="text-destructive text-sm">{{ form.errors.name }}</p>
        </div>

        <div class="grid gap-2">
            <Label for="contractor-legal-name">იურიდიული დასახელება</Label>
            <Input id="contractor-legal-name" v-model="form.legal_name" maxlength="255" />
            <p v-if="form.errors.legal_name" class="text-destructive text-sm">{{ form.errors.legal_name }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="contractor-tax-id">საიდენტიფიკაციო კოდი</Label>
                <Input id="contractor-tax-id" v-model="form.tax_id" maxlength="64" />
                <p v-if="form.errors.tax_id" class="text-destructive text-sm">{{ form.errors.tax_id }}</p>
            </div>
            <div class="grid gap-2">
                <Label for="contractor-currency">ვალუტა</Label>
                <Input id="contractor-currency" v-model="form.default_currency" required maxlength="3" />
                <p v-if="form.errors.default_currency" class="text-destructive text-sm">{{ form.errors.default_currency }}</p>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="contractor-contact">საკონტაქტო პირი</Label>
                <Input id="contractor-contact" v-model="form.contact_person" maxlength="255" />
                <p v-if="form.errors.contact_person" class="text-destructive text-sm">{{ form.errors.contact_person }}</p>
            </div>
            <div class="grid gap-2">
                <Label for="contractor-phone">ტელეფონი</Label>
                <Input id="contractor-phone" v-model="form.phone" maxlength="64" />
                <p v-if="form.errors.phone" class="text-destructive text-sm">{{ form.errors.phone }}</p>
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="contractor-email">ელფოსტა</Label>
            <Input id="contractor-email" v-model="form.email" type="email" maxlength="255" />
            <p v-if="form.errors.email" class="text-destructive text-sm">{{ form.errors.email }}</p>
        </div>

        <div class="grid gap-2">
            <Label for="contractor-notes">შენიშვნები</Label>
            <textarea
                id="contractor-notes"
                v-model="form.notes"
                rows="3"
                maxlength="2000"
                class="border-input bg-background rounded-md border px-3 py-2 text-sm"
            ></textarea>
            <p v-if="form.errors.notes" class="text-destructive text-sm">{{ form.errors.notes }}</p>
        </div>

        <label class="border-border flex cursor-pointer items-center gap-3 rounded-lg border p-3">
            <input v-model="form.is_active" type="checkbox" class="size-4" />
            <span>
                <span class="block text-sm font-medium">აქტიური კონტრაქტორი</span>
                <span class="text-muted-foreground block text-xs">გათიშვა ისტორიას არ წაშლის.</span>
            </span>
        </label>

        <Button type="submit" :disabled="form.processing">
            {{ contractor ? 'ცვლილებების შენახვა' : 'კონტრაქტორის დამატება' }}
        </Button>
    </form>
</template>
