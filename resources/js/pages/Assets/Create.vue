<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EntityPicker from '@/components/EntityPicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({ layout: { mobileTitle: 'აქტივის რეგისტრაცია' } });

const form = useForm({
    name: '',
    category: '',
    tracking_type: 'individual',
    inventory_code: '',
    initial_location_type: 'site',
    initial_location_id: '',
    condition: 'new',
    brand: '',
    model: '',
    serial_number: '',
    ownership: 'owned',
    quantity_on_hand: '',
});

// Shown next to the picker; `form.initial_location_id` carries the id that
// is actually submitted (A13 — the id is never shown or typed).
const locationName = ref<string | null>(null);

// A site id is meaningless once the type switches to employee, so the
// previous choice is dropped rather than silently carried over.
function resetLocation() {
    form.initial_location_id = '';
    locationName.value = null;
}

function submit() {
    form.post('/assets', { preserveScroll: true });
}
</script>

<template>
    <Head title="აქტივის რეგისტრაცია" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/assets" class="text-muted-foreground text-sm hover:underline">← აქტივები</Link>
            <h1 class="mt-2 text-2xl font-semibold">აქტივის რეგისტრაცია</h1>
        </div>

        <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="asset-name">დასახელება</Label>
                <Input id="asset-name" v-model="form.name" required maxlength="255" />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="asset-category">კატეგორია</Label>
                    <Input id="asset-category" v-model="form.category" required maxlength="255" />
                    <InputError :message="form.errors.category" />
                </div>
                <div class="grid gap-2">
                    <Label for="asset-code">ინვენტარიზაციის კოდი</Label>
                    <Input id="asset-code" v-model="form.inventory_code" required maxlength="255" />
                    <InputError :message="form.errors.inventory_code" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="asset-tracking-type">აღრიცხვის ტიპი</Label>
                    <select id="asset-tracking-type" v-model="form.tracking_type" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="individual">ინდივიდუალური (სერიული)</option>
                        <option value="kit_component">ნაკრების კომპონენტი</option>
                        <option value="quantity">რაოდენობრივი</option>
                        <option value="consumable">ხარჯვადი მასალა</option>
                    </select>
                    <InputError :message="form.errors.tracking_type" />
                </div>
                <div class="grid gap-2">
                    <Label for="asset-condition">მდგომარეობა</Label>
                    <select id="asset-condition" v-model="form.condition" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="new">ახალი</option>
                        <option value="good">კარგი</option>
                        <option value="fair">დამაკმაყოფილებელი</option>
                        <option value="damaged">დაზიანებული</option>
                    </select>
                    <InputError :message="form.errors.condition" />
                </div>
            </div>

            <div v-if="form.tracking_type === 'quantity' || form.tracking_type === 'consumable'" class="grid gap-2">
                <Label for="asset-quantity">ნაშთი</Label>
                <Input id="asset-quantity" v-model="form.quantity_on_hand" type="number" min="0" step="0.01" />
                <InputError :message="form.errors.quantity_on_hand" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="asset-brand">ბრენდი</Label>
                    <Input id="asset-brand" v-model="form.brand" maxlength="255" />
                </div>
                <div class="grid gap-2">
                    <Label for="asset-model">მოდელი</Label>
                    <Input id="asset-model" v-model="form.model" maxlength="255" />
                </div>
                <div class="grid gap-2">
                    <Label for="asset-serial">სერიული ნომერი</Label>
                    <Input id="asset-serial" v-model="form.serial_number" maxlength="255" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="asset-location-type">საწყისი მდებარეობა</Label>
                    <select id="asset-location-type" v-model="form.initial_location_type" class="border-input bg-background h-9 rounded-md border px-3 text-sm" @change="resetLocation">
                        <option value="site">ობიექტი</option>
                        <option value="employee">თანამშრომელი</option>
                    </select>
                    <InputError :message="form.errors.initial_location_type" />
                </div>
                <div class="grid gap-2">
                    <Label for="asset-location-id">{{ form.initial_location_type === 'site' ? 'ობიექტი' : 'თანამშრომელი' }}</Label>
                    <EntityPicker
                        :id="`asset-location-${form.initial_location_type}`"
                        v-model="form.initial_location_id"
                        v-model:selected-label="locationName"
                        :endpoint="`/assets/location-options?type=${form.initial_location_type}`"
                        :placeholder="form.initial_location_type === 'site' ? 'მოძებნეთ ობიექტი' : 'მოძებნეთ თანამშრომელი'"
                        empty-text="ვერ მოიძებნა"
                    />
                    <InputError :message="form.errors.initial_location_id" />
                </div>
            </div>

            <Button type="submit" :disabled="form.processing">აქტივის დამატება</Button>
        </form>
    </div>
</template>
