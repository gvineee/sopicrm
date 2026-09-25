<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import DesiredAcknowledgedState from '@/components/Devices/DesiredAcknowledgedState.vue';
import SimulatorModeBadge from '@/components/Devices/SimulatorModeBadge.vue';
import BioStarReadOnlyBanner from '@/components/Devices/BioStarReadOnlyBanner.vue';
import type { StatusTone } from '@/types';

type ActiveAssignment = {
    id: string;
    employee_id: string;
    employee_name?: string | null;
    valid_from: string;
    valid_to?: string | null;
    site_scope?: string[] | null;
};

type DeviceSync = {
    device_id: string;
    device_serial: string;
    desired: string;
    acknowledged: string;
    is_pending: boolean;
};

type Credential = {
    id: string;
    card_type: string;
    canonical_identifier: string;
    raw_bytes_hex?: string | null;
    status: string;
    active_assignment: ActiveAssignment | null;
    device_sync: DeviceSync[];
};

type PaginatedCredentials = {
    data: Credential[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    credentials: PaginatedCredentials;
    filters: { search?: string };
    employees: Array<{ id: string; first_name: string; last_name: string }>;
    sites: Array<{ id: string; name: string }>;
    canManage: boolean;
    isSimulatorMode: boolean;
    biostarReadOnly: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'ბარათები' } });

const CREDENTIAL_STATUS_TONE: Record<string, StatusTone> = {
    unassigned: 'neutral',
    issued: 'success',
    lost: 'warning',
    revoked: 'destructive',
    expired: 'destructive',
};
const CREDENTIAL_STATUS_LABEL: Record<string, string> = {
    unassigned: 'გაუცემელი',
    issued: 'გაცემული',
    lost: 'დაკარგული',
    revoked: 'გაუქმებული',
    expired: 'ვადაგასული',
};

const issueForm = useForm({
    card_type: 'EM',
    input_format: 'hex' as 'hex' | 'decimal',
    card_value: '',
    bit_length: '',
    employee_id: '',
    valid_from: '',
    valid_to: '',
    site_ids: [] as string[],
});

function issueCredential() {
    issueForm
        .transform((data) => ({
            ...data,
            bit_length: data.bit_length || null,
            valid_from: data.valid_from || null,
            valid_to: data.valid_to || null,
            site_ids: data.site_ids.length ? data.site_ids : null,
        }))
        .post('/credentials', {
            preserveScroll: true,
            onSuccess: () => issueForm.reset('card_value', 'bit_length', 'valid_from', 'valid_to'),
        });
}

// Reissue/revoke are per-row, so each row gets its own useForm-like state
// kept in a plain reactive map instead of one shared form — avoids one
// credential's in-progress edit leaking into another row's inputs.
const openReissue = reactive<Record<string, boolean>>({});
const openRevoke = reactive<Record<string, boolean>>({});
const reissueForms = reactive<
    Record<string, ReturnType<typeof useForm<{ employee_id: string; valid_from: string; valid_to: string; reason: string }>>>
>({});
const revokeForms = reactive<Record<string, ReturnType<typeof useForm<{ reason: string }>>>>({});

function reissueFormFor(credentialId: string) {
    if (!reissueForms[credentialId]) {
        reissueForms[credentialId] = useForm({ employee_id: '', valid_from: '', valid_to: '', reason: '' });
    }
    return reissueForms[credentialId];
}

function revokeFormFor(credentialId: string) {
    if (!revokeForms[credentialId]) {
        revokeForms[credentialId] = useForm({ reason: '' });
    }
    return revokeForms[credentialId];
}

function submitReissue(credentialId: string) {
    reissueFormFor(credentialId)
        .transform((data) => ({ ...data, valid_from: data.valid_from || null, valid_to: data.valid_to || null }))
        .post(`/credentials/${credentialId}/reissue`, {
            preserveScroll: true,
            onSuccess: () => {
                openReissue[credentialId] = false;
            },
        });
}

function submitRevoke(assignmentId: string, credentialId: string) {
    revokeFormFor(credentialId).post(`/credential-assignments/${assignmentId}/revoke`, {
        preserveScroll: true,
        onSuccess: () => {
            openRevoke[credentialId] = false;
        },
    });
}

function employeeName(employeeId: string): string {
    const employee = props.employees.find((item) => item.id === employeeId);
    return employee ? `${employee.first_name} ${employee.last_name}` : employeeId;
}
</script>

<template>
    <Head title="ბარათები" />
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-5 p-4 md:p-6">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold">ბარათები</h1>
                <SimulatorModeBadge v-if="isSimulatorMode" />
            </div>
            <p class="text-muted-foreground text-sm">
                გაცემა, ხელახალი გაცემა და გაუქმება — რეალურ hardware-ზე გავრცელება sync
                command queue-ს გავლით ხდება, ცალკეული სქემის მიხედვით.
            </p>
        </div>

        <BioStarReadOnlyBanner v-if="biostarReadOnly" />

        <form
            v-if="canManage"
            class="border-border bg-card grid gap-3 rounded-xl border p-5 md:grid-cols-3"
            @submit.prevent="issueCredential"
        >
            <div class="grid gap-2 md:col-span-3">
                <Label class="text-base">ახალი ბარათის გაცემა</Label>
            </div>
            <div class="grid gap-2">
                <Label>ტიპი</Label>
                <Input v-model="issueForm.card_type" placeholder="EM / MIFARE" required />
            </div>
            <div class="grid gap-2">
                <Label>ფორმატი</Label>
                <select
                    v-model="issueForm.input_format"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="hex">თექვსმეტობითი (Hex)</option>
                    <option value="decimal">ათობითი (Decimal)</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label>ბარათის ნომერი</Label>
                <Input v-model="issueForm.card_value" required placeholder="მაგ. 0A1B2C" />
                <p v-if="issueForm.errors.card_value" class="text-destructive text-sm">
                    {{ issueForm.errors.card_value }}
                </p>
            </div>
            <div class="grid gap-2">
                <Label>Bit length (არასავალდებულო)</Label>
                <Input v-model="issueForm.bit_length" type="number" min="1" max="256" />
            </div>
            <div class="grid gap-2">
                <Label>თანამშრომელი</Label>
                <select
                    v-model="issueForm.employee_id"
                    required
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="" disabled>აირჩიეთ თანამშრომელი</option>
                    <option v-for="employee in employees" :key="employee.id" :value="employee.id">
                        {{ employee.first_name }} {{ employee.last_name }}
                    </option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div class="grid gap-2">
                    <Label>მოქმედია დან</Label>
                    <Input v-model="issueForm.valid_from" type="date" />
                </div>
                <div class="grid gap-2">
                    <Label>მოქმედია მდე</Label>
                    <Input v-model="issueForm.valid_to" type="date" />
                </div>
            </div>
            <div class="grid gap-2 md:col-span-3">
                <Label>ობიექტები (ცარიელი = ყველა)</Label>
                <div class="flex flex-wrap gap-3">
                    <label
                        v-for="site in sites"
                        :key="site.id"
                        class="border-border flex items-center gap-2 rounded-md border px-2 py-1 text-sm"
                    >
                        <input type="checkbox" :value="site.id" v-model="issueForm.site_ids" />
                        {{ site.name }}
                    </label>
                </div>
            </div>
            <div class="md:col-span-3">
                <Button type="submit" :disabled="issueForm.processing">ბარათის გაცემა</Button>
            </div>
        </form>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="credentials.data.length === 0"
                title="ბარათი ვერ მოიძებნა"
                description="ჯერ არცერთი ბარათი არ არის გაცემული."
            />
            <div v-else class="divide-border divide-y">
                <div v-for="credential in credentials.data" :key="credential.id" class="p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ credential.card_type }} · {{ credential.canonical_identifier }}
                            </p>
                            <p class="text-muted-foreground truncate text-sm">
                                {{
                                    credential.active_assignment
                                        ? (credential.active_assignment.employee_name ||
                                          employeeName(credential.active_assignment.employee_id))
                                        : 'მიმღები არ არის მინიჭებული'
                                }}
                            </p>
                        </div>
                        <StatusBadge
                            :label="CREDENTIAL_STATUS_LABEL[credential.status] || credential.status"
                            :tone="CREDENTIAL_STATUS_TONE[credential.status] || 'neutral'"
                        />
                    </div>

                    <div v-if="credential.device_sync.length" class="mt-3 grid gap-2">
                        <DesiredAcknowledgedState
                            v-for="sync in credential.device_sync"
                            :key="sync.device_id"
                            :device-serial="sync.device_serial"
                            :desired="sync.desired"
                            :acknowledged="sync.acknowledged"
                            :is-pending="sync.is_pending"
                        />
                    </div>

                    <div v-if="canManage && credential.active_assignment" class="mt-3 flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="openReissue[credential.id] = !openReissue[credential.id]"
                            >ხელახლა გაცემა</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            class="border-destructive text-destructive"
                            @click="openRevoke[credential.id] = !openRevoke[credential.id]"
                            >გაუქმება</Button
                        >
                    </div>

                    <form
                        v-if="openReissue[credential.id]"
                        class="border-border mt-3 grid gap-2 rounded-lg border p-3 md:grid-cols-2"
                        @submit.prevent="submitReissue(credential.id)"
                    >
                        <select
                            v-model="reissueFormFor(credential.id).employee_id"
                            required
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="" disabled>ახალი მიმღები</option>
                            <option v-for="employee in employees" :key="employee.id" :value="employee.id">
                                {{ employee.first_name }} {{ employee.last_name }}
                            </option>
                        </select>
                        <Input
                            v-model="reissueFormFor(credential.id).reason"
                            placeholder="ცვლილების მიზეზი"
                            required
                        />
                        <Input v-model="reissueFormFor(credential.id).valid_from" type="date" />
                        <Input v-model="reissueFormFor(credential.id).valid_to" type="date" />
                        <Button
                            type="submit"
                            size="sm"
                            class="md:col-span-2 w-fit"
                            :disabled="reissueFormFor(credential.id).processing"
                            >დადასტურება</Button
                        >
                    </form>

                    <form
                        v-if="openRevoke[credential.id]"
                        class="border-destructive/30 mt-3 grid gap-2 rounded-lg border p-3"
                        @submit.prevent="submitRevoke(credential.active_assignment!.id, credential.id)"
                    >
                        <Input v-model="revokeFormFor(credential.id).reason" placeholder="გაუქმების მიზეზი" required />
                        <Button
                            type="submit"
                            variant="destructive"
                            size="sm"
                            class="w-fit"
                            :disabled="revokeFormFor(credential.id).processing"
                            >გაუქმების დადასტურება</Button
                        >
                    </form>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground"
                >გვერდი {{ credentials.current_page }} / {{ credentials.last_page }}</span
            >
            <div class="flex gap-2">
                <Button v-if="credentials.prev_page_url" as-child variant="outline" size="sm"
                    ><a :href="credentials.prev_page_url!">წინა</a></Button
                >
                <Button v-if="credentials.next_page_url" as-child variant="outline" size="sm"
                    ><a :href="credentials.next_page_url!">შემდეგი</a></Button
                >
            </div>
        </div>
    </div>
</template>
