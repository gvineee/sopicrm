<script setup lang="ts">
/**
 * Audit A24: „კლიენტი" existed on the project form as a dropdown that nothing
 * could ever fill — no screen, no route, and the permission its Policy checks
 * had never been created. This is that screen.
 *
 * It also states the distinction the audit asked to be made visible:
 * a Company is one of our own legal entities, a Client is who a project is
 * built for, a Contractor is who is hired to do part of it.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmptyState from '@/components/states/EmptyState.vue';

type ContactInfo = {
    contact_person?: string | null;
    phone?: string | null;
    email?: string | null;
    note?: string | null;
} | null;

type ClientRow = {
    id: string;
    name: string;
    contact_info: ContactInfo;
    project_count: number;
};

const props = defineProps<{
    clients: ClientRow[];
    pagination: { page: number; perPage: number; total: number };
    filters: { search: string };
    can: { manage: boolean };
}>();

defineOptions({ layout: { mobileTitle: 'კლიენტები' } });

const editingId = ref<string | null>(null);

const form = useForm({
    name: '',
    contact_person: '',
    phone: '',
    email: '',
    note: '',
});

function startCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function startEdit(client: ClientRow) {
    editingId.value = client.id;
    form.clearErrors();
    form.name = client.name;
    form.contact_person = client.contact_info?.contact_person ?? '';
    form.phone = client.contact_info?.phone ?? '';
    form.email = client.contact_info?.email ?? '';
    form.note = client.contact_info?.note ?? '';
}

function submit() {
    if (editingId.value) {
        form.put(`/clients/${editingId.value}`, { preserveScroll: true, onSuccess: startCreate });

        return;
    }

    form.post('/clients', { preserveScroll: true, onSuccess: startCreate });
}

const lastPage = Math.max(1, Math.ceil(props.pagination.total / props.pagination.perPage));
</script>

<template>
    <Head title="კლიენტები" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">კლიენტები</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                კლიენტი არის ის, ვისთვისაც პროექტი სრულდება. ეს არ არის
                <Link href="/companies" class="underline">კომპანია</Link> (ჩვენი საკუთარი იურიდიული პირი),
                არც <Link href="/contractors" class="underline">კონტრაქტორი</Link> (ვინც სამუშაოს ნაწილს ასრულებს).
            </p>
        </div>

        <form method="get" action="/clients" class="flex flex-wrap gap-2">
            <Input name="search" :default-value="filters.search" placeholder="ძიება დასახელებით" class="max-w-xs" />
            <Button type="submit" variant="outline">ძიება</Button>
            <Button v-if="filters.search" as-child variant="ghost"><Link href="/clients">გასუფთავება</Link></Button>
        </form>

        <form v-if="can.manage" class="border-border bg-card grid gap-3 rounded-xl border p-5" @submit.prevent="submit">
            <h2 class="font-semibold">{{ editingId ? 'კლიენტის რედაქტირება' : 'ახალი კლიენტი' }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>დასახელება</Label>
                    <Input v-model="form.name" required />
                    <p v-if="form.errors.name" class="text-destructive text-xs">{{ form.errors.name }}</p>
                </div>
                <div class="grid gap-2">
                    <Label>საკონტაქტო პირი</Label>
                    <Input v-model="form.contact_person" />
                </div>
                <div class="grid gap-2">
                    <Label>ტელეფონი</Label>
                    <Input v-model="form.phone" />
                </div>
                <div class="grid gap-2">
                    <Label>ელფოსტა</Label>
                    <Input v-model="form.email" type="email" />
                    <p v-if="form.errors.email" class="text-destructive text-xs">{{ form.errors.email }}</p>
                </div>
            </div>
            <div class="grid gap-2">
                <Label>შენიშვნა</Label>
                <textarea v-model="form.note" rows="2" class="border-input bg-background rounded-md border px-3 py-2 text-sm" />
            </div>
            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ editingId ? 'შენახვა' : 'დამატება' }}</Button>
                <Button v-if="editingId" type="button" variant="ghost" @click="startCreate">გაუქმება</Button>
            </div>
        </form>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="clients.length === 0"
                :title="filters.search ? 'კლიენტი ვერ მოიძებნა' : 'კლიენტი ჯერ არ არის'"
                :description="filters.search ? 'სცადეთ სხვა საძიებო სიტყვა.' : 'დაამატეთ პირველი კლიენტი, რომ პროექტს დაუკავშიროთ.'"
            />
            <div v-else class="divide-border divide-y">
                <div v-for="client in clients" :key="client.id" class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="font-medium">{{ client.name }}</p>
                        <p class="text-muted-foreground text-sm">
                            <span v-if="client.contact_info?.contact_person">{{ client.contact_info.contact_person }}</span>
                            <span v-if="client.contact_info?.phone"> · {{ client.contact_info.phone }}</span>
                            <span v-if="client.contact_info?.email"> · {{ client.contact_info.email }}</span>
                            <span v-if="!client.contact_info">საკონტაქტო მონაცემი არ არის</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <!-- Shown before renaming: a client several projects
                             point at is not a row to edit casually. -->
                        <span class="text-muted-foreground text-sm">{{ client.project_count }} პროექტი</span>
                        <Button v-if="can.manage" variant="outline" size="sm" @click="startEdit(client)">რედაქტირება</Button>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-muted-foreground text-sm">გვერდი {{ pagination.page }} / {{ lastPage }} · სულ {{ pagination.total }}</p>
    </div>
</template>
