<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Organization = {
    id: string;
    name: string;
    legal_name: string | null;
    users_count: number;
    employees_count: number;
    projects_count: number;
};

const props = defineProps<{
    organization: Organization;
    isOwnOrganization: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'ორგანიზაციის მართვა' } });

const form = useForm({
    name: props.organization.name,
    legal_name: props.organization.legal_name ?? '',
});

function submit() {
    form.put(`/platform/organizations/${props.organization.id}`, { preserveScroll: true });
}

const deleteDialogOpen = ref(false);
const deleteForm = useForm({ confirmation_name: '' });
const canConfirmDelete = computed(() => deleteForm.confirmation_name === props.organization.name);
// The server reports refusals that are about the organization itself (own
// organization, blocked by another organization's data) under this key.
const organizationError = computed(() => (deleteForm.errors as Record<string, string | undefined>).organization);

function openDeleteDialog() {
    deleteForm.reset();
    deleteForm.clearErrors();
    deleteDialogOpen.value = true;
}

function confirmDelete() {
    if (!canConfirmDelete.value) return;

    deleteForm.delete(`/platform/organizations/${props.organization.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="organization.name" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/platform/organizations" class="text-muted-foreground text-sm hover:underline">← ორგანიზაციები</Link>
            <h1 class="mt-2 text-2xl font-semibold">ორგანიზაციის მართვა</h1>
        </div>

        <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
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
            <Button type="submit" class="w-fit" :disabled="form.processing">ცვლილებების შენახვა</Button>
        </form>

        <div class="border-destructive/30 bg-destructive/5 flex flex-col gap-3 rounded-xl border p-5">
            <div>
                <h2 class="text-destructive text-sm font-semibold">საშიში ზონა</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    ორგანიზაციის წაშლა შეუქცევადია: სამუდამოდ იშლება მისი ყველა ჩანაწერი — მომხმარებლები
                    ({{ organization.users_count }}), თანამშრომლები ({{ organization.employees_count }}), პროექტები
                    ({{ organization.projects_count }}), დავალებები, დასწრება, მოწყობილობები და დანარჩენი ყველაფერი.
                </p>
            </div>
            <p v-if="isOwnOrganization" class="text-sm font-medium">ეს თქვენი საკუთარი ორგანიზაციაა — მისი წაშლა შეუძლებელია.</p>
            <InputError :message="organizationError" />
            <Button variant="destructive" class="w-fit" :disabled="isOwnOrganization" @click="openDeleteDialog">
                ორგანიზაციის სრული წაშლა
            </Button>
        </div>

        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>წავშალოთ „{{ organization.name }}“ სამუდამოდ?</DialogTitle>
                    <DialogDescription>
                        დასადასტურებლად ჩაწერეთ ორგანიზაციის ზუსტი დასახელება: <strong>{{ organization.name }}</strong>
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="delete-confirm-name">ორგანიზაციის დასახელება</Label>
                    <Input id="delete-confirm-name" v-model="deleteForm.confirmation_name" autocomplete="off" />
                    <InputError :message="deleteForm.errors.confirmation_name" />
                    <InputError :message="organizationError" />
                </div>
                <DialogFooter>
                    <Button variant="outline" :disabled="deleteForm.processing" @click="deleteDialogOpen = false">გაუქმება</Button>
                    <Button variant="destructive" :disabled="!canConfirmDelete || deleteForm.processing" @click="confirmDelete">
                        დიახ, წაშალე სამუდამოდ
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
