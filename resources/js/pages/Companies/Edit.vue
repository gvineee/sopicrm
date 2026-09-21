<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import CompanyForm from '@/components/Companies/CompanyForm.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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

const props = defineProps<{ company: Company }>();

defineOptions({ layout: { mobileTitle: 'კომპანიის რედაქტირება' } });

const deleteDialogOpen = ref(false);
const confirmationText = ref('');
const isDeleting = ref(false);
const canConfirmDelete = () => confirmationText.value.trim() === props.company.name;

function openDeleteDialog() {
    confirmationText.value = '';
    deleteDialogOpen.value = true;
}

function confirmDelete() {
    if (!canConfirmDelete()) return;

    isDeleting.value = true;
    router.delete(`/companies/${props.company.id}`, {
        preserveScroll: true,
        onFinish: () => {
            isDeleting.value = false;
            deleteDialogOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="company.name" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/companies" class="text-muted-foreground text-sm hover:underline">← კომპანიები</Link>
            <h1 class="mt-2 text-2xl font-semibold">კომპანიის რედაქტირება</h1>
        </div>
        <CompanyForm :company="company" />

        <div class="border-destructive/30 bg-destructive/5 flex flex-col gap-3 rounded-xl border p-5">
            <div>
                <h2 class="text-destructive text-sm font-semibold">საშიში ზონა</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    კომპანიის წაშლა შეუქცევადია. მასთან დაკავშირებული პროექტები და წევრობები დაკარგავენ ამ კომპანიასთან კავშირს.
                </p>
            </div>
            <Button variant="destructive" class="w-fit" @click="openDeleteDialog">კომპანიის წაშლა</Button>
        </div>

        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>წავშალოთ „{{ company.name }}“?</DialogTitle>
                    <DialogDescription>
                        დასადასტურებლად ჩაწერეთ კომპანიის ზუსტი დასახელება: <strong>{{ company.name }}</strong>
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="delete-confirm-name">კომპანიის დასახელება</Label>
                    <Input id="delete-confirm-name" v-model="confirmationText" autocomplete="off" />
                </div>
                <DialogFooter>
                    <Button variant="outline" :disabled="isDeleting" @click="deleteDialogOpen = false">გაუქმება</Button>
                    <Button variant="destructive" :disabled="!canConfirmDelete() || isDeleting" @click="confirmDelete">
                        დიახ, წაშალე
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
