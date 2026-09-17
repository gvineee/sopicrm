<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Employee = {
    id: string;
    internal_code: string;
    first_name: string;
    last_name: string;
    full_name: string;
    phone?: string | null;
    position?: string | null;
    photo_url?: string | null;
    status: string;
    team?: { id: string; name: string } | null;
    personal_id_number?: string | null;
    personal_id_number_visible: boolean;
    has_login: boolean;
};

type Props = {
    employee: Employee;
    employments: Array<{
        id: string;
        started_at: string;
        ended_at: string | null;
        end_reason: string | null;
        status: string;
    }>;
    rateHistories: Array<{
        id: string;
        rate_type: string;
        amount: string;
        currency: string;
        effective_from: string;
        effective_to: string | null;
        project_name?: string | null;
    }> | null;
    canViewRates: boolean;
    canManageRates: boolean;
    canViewDocuments: boolean;
    canManageDocuments: boolean;
    canManageInvite: boolean;
    canTerminate: boolean;
    canEdit: boolean;
    projectAssignments: Array<{
        id: string;
        project_name?: string | null;
        starts_on: string;
        ends_on: string | null;
        assignment_type?: string | null;
    }>;
    documents: Array<{
        id: string;
        name: string;
        caption?: string | null;
        mime_type: string;
        byte_size: number;
        download_url: string;
    }>;
    pendingInvite?: { id: string; expires_at: string } | null;
    teams: Array<{ id: string; name: string }>;
    projects: Array<{ id: string; name: string }>;
    inviteUrl?: string | null;
    termination?: {
        login_revoked: boolean;
        device_sync_commands_scheduled: number;
        unreturned_custody_transactions: unknown[];
    } | null;
};

const props = defineProps<Props>();
defineOptions({ layout: { mobileTitle: 'თანამშრომლის პროფილი' } });

const rateForm = useForm({
    rate_type: 'hourly',
    amount: '',
    currency: 'GEL',
    effective_from: '',
    effective_to: '',
    project_id: '',
    change_reason: '',
});
const teamForm = useForm({
    team_id: props.employee.team?.id ?? '',
    started_on: new Date().toISOString().slice(0, 10),
});
const assignmentForm = useForm({
    project_id: '',
    starts_on: '',
    ends_on: '',
    assignment_type: '',
});
const terminationForm = useForm({
    ended_on: new Date().toISOString().slice(0, 10),
    end_reason: '',
});
const inviteForm = useForm({});
const documentForm = useForm<{ file: File | null; caption: string }>({
    file: null,
    caption: '',
});
const photoForm = useForm<{ photo: File | null }>({ photo: null });

function createRate() {
    rateForm
        .transform((data) => ({
            ...data,
            project_id: data.project_id || null,
            effective_to: data.effective_to || null,
        }))
        .post(`/employees/${props.employee.id}/rates`, {
            preserveScroll: true,
            onSuccess: () =>
                rateForm.reset(
                    'amount',
                    'effective_from',
                    'effective_to',
                    'change_reason',
                ),
        });
}

function setTeam() {
    teamForm
        .transform((data) => ({ ...data, team_id: data.team_id || null }))
        .post(`/employees/${props.employee.id}/team-membership`, {
            preserveScroll: true,
        });
}

function addAssignment() {
    assignmentForm
        .transform((data) => ({
            ...data,
            ends_on: data.ends_on || null,
            assignment_type: data.assignment_type || null,
        }))
        .post(`/employees/${props.employee.id}/project-assignments`, {
            preserveScroll: true,
            onSuccess: () => assignmentForm.reset(),
        });
}

function selectDocument(event: Event) {
    const target = event.target as HTMLInputElement;
    documentForm.file = target.files?.[0] ?? null;
}

function uploadDocument() {
    documentForm.post(`/employees/${props.employee.id}/documents`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => documentForm.reset(),
    });
}

function uploadPhoto(event: Event) {
    const target = event.target as HTMLInputElement;
    photoForm.photo = target.files?.[0] ?? null;

    if (photoForm.photo) {
        photoForm.post(`/employees/${props.employee.id}/photo`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => photoForm.reset(),
        });
    }
}
</script>

<template>
    <Head :title="employee.full_name" />
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-center gap-4">
                <div class="bg-muted h-16 w-16 overflow-hidden rounded-full">
                    <img
                        v-if="employee.photo_url"
                        :src="employee.photo_url"
                        :alt="employee.full_name"
                        class="h-full w-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-full items-center justify-center text-lg font-semibold"
                    >
                        {{ employee.first_name.slice(0, 1)
                        }}{{ employee.last_name.slice(0, 1) }}
                    </div>
                </div>
                <div>
                    <Link
                        href="/employees"
                        class="text-muted-foreground text-sm hover:underline"
                        >← თანამშრომლები</Link
                    >
                    <h1 class="mt-2 text-2xl font-semibold">
                        {{ employee.full_name }}
                    </h1>
                    <p class="text-muted-foreground text-sm">
                        {{ employee.internal_code }} ·
                        {{ employee.position || 'პოზიცია არ არის მითითებული' }}
                    </p>
                    <label
                        v-if="canEdit"
                        class="text-primary mt-1 inline-block cursor-pointer text-xs hover:underline"
                    >
                        ფოტოს შეცვლა
                        <input
                            class="sr-only"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            @change="uploadPhoto"
                        />
                    </label>
                </div>
            </div>
            <Button v-if="canEdit" as-child variant="outline"
                ><Link :href="`/employees/${employee.id}/edit`"
                    >რედაქტირება</Link
                ></Button
            >
        </div>

        <div
            v-if="inviteUrl"
            class="border-primary/30 bg-primary/5 rounded-xl border p-4"
        >
            <p class="font-medium">ერთჯერადი მოწვევის ბმული</p>
            <p class="mt-1 text-sm break-all">{{ inviteUrl }}</p>
        </div>

        <div
            v-if="termination"
            class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100"
        >
            <p class="font-medium">დასაქმება დასრულდა</p>
            <p class="mt-1 text-sm">
                Login გაუქმდა: {{ termination.login_revoked ? 'კი' : 'არა' }} ·
                მოწყობილობის ბრძანებები:
                {{ termination.device_sync_commands_scheduled }} · დასაბრუნებელი
                ოპერაციები:
                {{ termination.unreturned_custody_transactions.length }}
            </p>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <section
                class="border-border bg-card rounded-xl border p-5 lg:col-span-2"
            >
                <h2 class="font-semibold">ძირითადი ინფორმაცია</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">ტელეფონი</dt>
                        <dd>{{ employee.phone || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">ბრიგადა</dt>
                        <dd>{{ employee.team?.name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">სტატუსი</dt>
                        <dd>{{ employee.status }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">ანგარიში</dt>
                        <dd>
                            {{
                                employee.has_login ? 'აქტივირებულია' : 'არ არის'
                            }}
                        </dd>
                    </div>
                    <div v-if="employee.personal_id_number_visible">
                        <dt class="text-muted-foreground">პირადი ნომერი</dt>
                        <dd>{{ employee.personal_id_number || '—' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">დასაქმების ისტორია</h2>
                <div class="mt-4 space-y-3 text-sm">
                    <div
                        v-for="employment in employments"
                        :key="employment.id"
                        class="border-border rounded-lg border p-3"
                    >
                        <p>
                            {{ employment.started_at }} —
                            {{ employment.ended_at || 'დღემდე' }}
                        </p>
                        <p class="text-muted-foreground">
                            {{ employment.end_reason || employment.status }}
                        </p>
                    </div>
                </div>
            </section>
        </div>

        <div v-if="canEdit" class="grid gap-5 lg:grid-cols-2">
            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">ბრიგადის შეცვლა</h2>
                <form class="mt-4 grid gap-3" @submit.prevent="setTeam">
                    <select
                        v-model="teamForm.team_id"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">ბრიგადის გარეშე</option>
                        <option
                            v-for="team in teams"
                            :key="team.id"
                            :value="team.id"
                        >
                            {{ team.name }}
                        </option>
                    </select>
                    <Input v-model="teamForm.started_on" type="date" required />
                    <Button type="submit" :disabled="teamForm.processing"
                        >განახლება</Button
                    >
                </form>
            </section>

            <section
                v-if="projects.length"
                class="border-border bg-card rounded-xl border p-5"
            >
                <h2 class="font-semibold">პროექტზე მინიჭება</h2>
                <form class="mt-4 grid gap-3" @submit.prevent="addAssignment">
                    <select
                        v-model="assignmentForm.project_id"
                        required
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="" disabled>აირჩიეთ პროექტი</option>
                        <option
                            v-for="project in projects"
                            :key="project.id"
                            :value="project.id"
                        >
                            {{ project.name }}
                        </option>
                    </select>
                    <div class="grid grid-cols-2 gap-3">
                        <Input
                            v-model="assignmentForm.starts_on"
                            type="date"
                            required
                        /><Input v-model="assignmentForm.ends_on" type="date" />
                    </div>
                    <Input
                        v-model="assignmentForm.assignment_type"
                        placeholder="მინიჭების ტიპი"
                    />
                    <Button type="submit" :disabled="assignmentForm.processing"
                        >დამატება</Button
                    >
                </form>
            </section>
        </div>

        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">პროექტებზე მინიჭებები</h2>
            <div
                v-if="projectAssignments.length"
                class="mt-4 grid gap-3 md:grid-cols-2"
            >
                <div
                    v-for="assignment in projectAssignments"
                    :key="assignment.id"
                    class="border-border rounded-lg border p-3 text-sm"
                >
                    <p class="font-medium">
                        {{ assignment.project_name || 'პროექტი' }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ assignment.starts_on }} —
                        {{ assignment.ends_on || 'დღემდე' }}
                    </p>
                </div>
            </div>
            <p v-else class="text-muted-foreground mt-3 text-sm">
                მინიჭებები ჯერ არ არის.
            </p>
        </section>

        <section
            v-if="canViewDocuments"
            class="border-border bg-card rounded-xl border p-5"
        >
            <h2 class="font-semibold">დოკუმენტები</h2>
            <div v-if="documents.length" class="mt-4 grid gap-2 md:grid-cols-2">
                <a
                    v-for="document in documents"
                    :key="document.id"
                    :href="document.download_url"
                    class="border-border hover:bg-muted/40 rounded-lg border p-3 text-sm"
                >
                    <p class="truncate font-medium">
                        {{ document.caption || document.name }}
                    </p>
                    <p class="text-muted-foreground truncate">
                        {{ document.name }} ·
                        {{ Math.ceil(document.byte_size / 1024) }} KB
                    </p>
                </a>
            </div>
            <p v-else class="text-muted-foreground mt-3 text-sm">
                დოკუმენტები ჯერ არ არის.
            </p>
            <form
                v-if="canManageDocuments"
                class="mt-5 grid gap-3 md:grid-cols-[1fr_1fr_auto]"
                @submit.prevent="uploadDocument"
            >
                <Input
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    required
                    @change="selectDocument"
                />
                <Input
                    v-model="documentForm.caption"
                    placeholder="აღწერა (არასავალდებულო)"
                />
                <Button
                    type="submit"
                    :disabled="documentForm.processing || !documentForm.file"
                    >ატვირთვა</Button
                >
            </form>
        </section>

        <section
            v-if="canViewRates"
            class="border-border bg-card rounded-xl border p-5"
        >
            <h2 class="font-semibold">ტარიფები</h2>
            <div class="mt-4 space-y-2">
                <div
                    v-for="rate in rateHistories || []"
                    :key="rate.id"
                    class="border-border grid gap-1 rounded-lg border p-3 text-sm md:grid-cols-4"
                >
                    <span>{{
                        rate.rate_type === 'hourly' ? 'საათობრივი' : 'დღიური'
                    }}</span
                    ><span>{{ rate.amount }} {{ rate.currency }}</span
                    ><span>{{ rate.project_name || 'საბაზო ტარიფი' }}</span
                    ><span
                        >{{ rate.effective_from }} —
                        {{ rate.effective_to || 'უვადო' }}</span
                    >
                </div>
            </div>
            <form
                v-if="canManageRates"
                class="mt-5 grid gap-3 md:grid-cols-3"
                @submit.prevent="createRate"
            >
                <select
                    v-model="rateForm.rate_type"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="hourly">საათობრივი</option>
                    <option value="daily">დღიური</option>
                </select>
                <Input
                    v-model="rateForm.amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    placeholder="თანხა"
                    required
                />
                <Input v-model="rateForm.currency" maxlength="3" required />
                <Input v-model="rateForm.effective_from" type="date" required />
                <Input v-model="rateForm.effective_to" type="date" />
                <select
                    v-model="rateForm.project_id"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="">საბაზო ტარიფი</option>
                    <option
                        v-for="project in projects"
                        :key="project.id"
                        :value="project.id"
                    >
                        {{ project.name }}
                    </option>
                </select>
                <Input
                    v-model="rateForm.change_reason"
                    class="md:col-span-2"
                    placeholder="ცვლილების მიზეზი"
                    required
                />
                <Button type="submit" :disabled="rateForm.processing"
                    >ტარიფის დამატება</Button
                >
            </form>
        </section>

        <div
            v-if="employee.status !== 'terminated'"
            class="grid gap-5 lg:grid-cols-2"
        >
            <section
                v-if="canManageInvite && !employee.has_login"
                class="border-border bg-card rounded-xl border p-5"
            >
                <h2 class="font-semibold">Login მოწვევა</h2>
                <p class="text-muted-foreground mt-2 text-sm">
                    ბმული ერთჯერადია და ვადაგასული გახდება კონფიგურირებული
                    პერიოდის შემდეგ.
                </p>
                <Button
                    class="mt-4"
                    :disabled="inviteForm.processing"
                    @click="
                        inviteForm.post(`/employees/${employee.id}/invites`)
                    "
                    >{{
                        pendingInvite
                            ? 'ახალი ბმულის შექმნა'
                            : 'მოწვევის შექმნა'
                    }}</Button
                >
            </section>
            <section
                v-if="canTerminate"
                class="border-destructive/30 bg-card rounded-xl border p-5"
            >
                <h2 class="font-semibold">დასაქმების დასრულება</h2>
                <form
                    class="mt-4 grid gap-3"
                    @submit.prevent="
                        terminationForm.post(
                            `/employees/${employee.id}/termination`,
                        )
                    "
                >
                    <div class="grid gap-2">
                        <Label>დასრულების თარიღი</Label
                        ><Input
                            v-model="terminationForm.ended_on"
                            type="date"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>მიზეზი</Label
                        ><Input v-model="terminationForm.end_reason" required />
                    </div>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="terminationForm.processing"
                        >დასრულება</Button
                    >
                </form>
            </section>
        </div>
    </div>
</template>
