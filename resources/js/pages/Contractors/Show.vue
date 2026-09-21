<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type Contractor = {
    id: string;
    name: string;
    legal_name: string | null;
    contact_person: string | null;
    phone: string | null;
    email: string | null;
    is_active: boolean;
};

type Contract = {
    id: string;
    project: { id: string; name: string } | null;
    contract_number: string | null;
    title: string;
    rate_type: string;
    rate_amount: string | null;
    total_amount: string | null;
    currency: string;
    starts_on: string | null;
    ends_on: string | null;
    status: 'draft' | 'pending_approval' | 'active' | 'closed';
};

type ProjectAssignment = {
    id: string;
    project_id: string;
    contract_id: string | null;
    starts_on: string | null;
    ends_on: string | null;
    scope_description: string | null;
};

type ProjectOption = { id: string; name: string };

const props = defineProps<{
    contractor: Contractor;
    contracts: Contract[];
    projectAssignments: ProjectAssignment[];
    pendingEvidence: Array<{ id: string; original_filename: string }>;
    projects: ProjectOption[];
    canManage: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'კონტრაქტორი' } });

const CONTRACT_STATUS_LABEL: Record<Contract['status'], string> = {
    draft: 'დრაფტი',
    pending_approval: 'დასამტკიცებელი',
    active: 'აქტიური',
    closed: 'დახურული',
};
const CONTRACT_STATUS_TONE: Record<Contract['status'], 'neutral' | 'warning' | 'success'> = {
    draft: 'neutral',
    pending_approval: 'warning',
    active: 'success',
    closed: 'neutral',
};

const contractForm = useForm({
    project_id: '',
    contract_number: '',
    title: '',
    description: '',
    rate_type: 'lump_sum',
    rate_amount: '',
    total_amount: '',
    currency: 'GEL',
    unit: '',
    starts_on: '',
    ends_on: '',
    terms: '',
});

function createContract() {
    contractForm.post(`/contractors/${props.contractor.id}/contracts`, {
        preserveScroll: true,
        onSuccess: () => contractForm.reset(),
    });
}

const assignmentForm = useForm({
    project_id: '',
    contract_id: '',
    starts_on: '',
    ends_on: '',
    scope_description: '',
});

function assignToProject() {
    if (!assignmentForm.project_id) return;
    assignmentForm.post(`/contractors/${props.contractor.id}/project-assignments/${assignmentForm.project_id}`, {
        preserveScroll: true,
        onSuccess: () => assignmentForm.reset(),
    });
}

function removeAssignment(assignment: ProjectAssignment) {
    if (!confirm('ნამდვილად გსურთ მინიჭების მოხსნა?')) return;
    router.delete(`/contractors/${props.contractor.id}/project-assignments/${assignment.project_id}/${assignment.id}`, { preserveScroll: true });
}

function projectName(projectId: string): string {
    return props.projects.find((p) => p.id === projectId)?.name ?? projectId;
}
</script>

<template>
    <Head :title="contractor.name" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link href="/contractors" class="text-muted-foreground text-sm hover:underline">← კონტრაქტორები</Link>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ contractor.name }}</h1>
                    <StatusBadge :label="contractor.is_active ? 'აქტიური' : 'გათიშული'" :tone="contractor.is_active ? 'success' : 'neutral'" />
                </div>
                <p class="text-muted-foreground text-sm">
                    {{ contractor.contact_person || '—' }} · {{ contractor.phone || '—' }} · {{ contractor.email || '—' }}
                </p>
            </div>
            <Button v-if="canManage" as-child variant="outline"><Link :href="`/contractors/${contractor.id}/edit`">რედაქტირება</Link></Button>
        </div>

        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">კონტრაქტები</h2>
            <EmptyState
                v-if="contracts.length === 0"
                class="mt-4"
                title="კონტრაქტი არ არსებობს"
                description="დაამატეთ პირველი კონტრაქტი ქვემოთ მოცემული ფორმით."
            />
            <div v-else class="divide-border mt-4 divide-y">
                <Link
                    v-for="contract in contracts"
                    :key="contract.id"
                    :href="`/contractors/${contractor.id}/contracts/${contract.id}`"
                    class="hover:bg-muted/50 flex flex-wrap items-center justify-between gap-2 py-3"
                >
                    <div>
                        <p class="font-medium">{{ contract.title }}</p>
                        <p class="text-muted-foreground text-sm">
                            {{ contract.project?.name || 'ყველა პროექტისთვის' }} · {{ contract.rate_type }} · {{ contract.currency }}
                        </p>
                    </div>
                    <StatusBadge :label="CONTRACT_STATUS_LABEL[contract.status]" :tone="CONTRACT_STATUS_TONE[contract.status]" />
                </Link>
            </div>

            <form v-if="canManage" class="border-border mt-5 grid gap-3 border-t pt-5" @submit.prevent="createContract">
                <h3 class="text-sm font-medium">ახალი კონტრაქტი</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="contract-title">სათაური</Label>
                        <Input id="contract-title" v-model="contractForm.title" required maxlength="255" />
                        <p v-if="contractForm.errors.title" class="text-destructive text-sm">{{ contractForm.errors.title }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="contract-project">პროექტი (არასავალდებულო)</Label>
                        <select id="contract-project" v-model="contractForm.project_id" class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                            <option value="">ყველა პროექტი</option>
                            <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="contract-rate-type">ანგარიშსწორების ტიპი</Label>
                        <select id="contract-rate-type" v-model="contractForm.rate_type" class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                            <option value="lump_sum">ჯამური თანხა</option>
                            <option value="unit_rate">ერთეულის ტარიფი</option>
                            <option value="hourly">საათობრივი</option>
                            <option value="daily">დღიური</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="contract-rate-amount">ტარიფი</Label>
                        <Input id="contract-rate-amount" v-model="contractForm.rate_amount" type="number" step="0.01" min="0" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="contract-total-amount">ჯამური თანხა</Label>
                        <Input id="contract-total-amount" v-model="contractForm.total_amount" type="number" step="0.01" min="0" />
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="contract-currency">ვალუტა</Label>
                        <Input id="contract-currency" v-model="contractForm.currency" required maxlength="3" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="contract-starts">დაწყება</Label>
                        <Input id="contract-starts" v-model="contractForm.starts_on" type="date" required />
                        <p v-if="contractForm.errors.starts_on" class="text-destructive text-sm">{{ contractForm.errors.starts_on }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="contract-ends">დასრულება</Label>
                        <Input id="contract-ends" v-model="contractForm.ends_on" type="date" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="contract-terms">პირობები</Label>
                    <textarea id="contract-terms" v-model="contractForm.terms" rows="3" class="border-input bg-background rounded-md border px-3 py-2 text-sm"></textarea>
                </div>
                <Button type="submit" class="w-fit" :disabled="contractForm.processing">კონტრაქტის შექმნა</Button>
            </form>
        </section>

        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">პროექტზე მინიჭება</h2>
            <EmptyState
                v-if="projectAssignments.length === 0"
                class="mt-4"
                title="მინიჭება არ არსებობს"
                description="მიანიჭეთ კონტრაქტორი პროექტს ქვემოთ."
            />
            <div v-else class="divide-border mt-4 divide-y">
                <div v-for="assignment in projectAssignments" :key="assignment.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <div>
                        <p class="font-medium">{{ projectName(assignment.project_id) }}</p>
                        <p class="text-muted-foreground text-sm">{{ assignment.starts_on }} — {{ assignment.ends_on || 'უვადოდ' }}</p>
                    </div>
                    <Button v-if="canManage" variant="outline" size="sm" @click="removeAssignment(assignment)">მოხსნა</Button>
                </div>
            </div>

            <form v-if="canManage" class="border-border mt-5 grid gap-3 border-t pt-5" @submit.prevent="assignToProject">
                <h3 class="text-sm font-medium">ახალი მინიჭება</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="assign-project">პროექტი</Label>
                        <select id="assign-project" v-model="assignmentForm.project_id" required class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                            <option value="" disabled>აირჩიეთ პროექტი</option>
                            <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="assign-contract">კონტრაქტი (არასავალდებულო)</Label>
                        <select id="assign-contract" v-model="assignmentForm.contract_id" class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                            <option value="">კონტრაქტის გარეშე</option>
                            <option v-for="contract in contracts" :key="contract.id" :value="contract.id">{{ contract.title }}</option>
                        </select>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="assign-starts">დაწყება</Label>
                        <Input id="assign-starts" v-model="assignmentForm.starts_on" type="date" required />
                        <p v-if="assignmentForm.errors.starts_on" class="text-destructive text-sm">{{ assignmentForm.errors.starts_on }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="assign-ends">დასრულება</Label>
                        <Input id="assign-ends" v-model="assignmentForm.ends_on" type="date" />
                    </div>
                </div>
                <Button type="submit" class="w-fit" :disabled="assignmentForm.processing">მინიჭება</Button>
            </form>
        </section>
    </div>
</template>
