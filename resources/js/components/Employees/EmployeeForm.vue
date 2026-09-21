<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = {
    id: string;
    name?: string;
    first_name?: string;
    last_name?: string;
};
type Employee = {
    id: string;
    internal_code: string;
    first_name: string;
    last_name: string;
    phone?: string | null;
    position?: string | null;
    position_id?: string | null;
    job_position?: { id: string; name: string } | null;
    profession_skills?: string[];
    team?: { id: string } | null;
    supervisor?: { id: string } | null;
    emergency_contact_name?: string | null;
    emergency_contact_phone?: string | null;
    personal_id_number?: string | null;
};

const props = defineProps<{
    mode: 'create' | 'edit';
    employee?: Employee;
    teams: Option[];
    supervisors: Option[];
    positions: Option[];
}>();

const form = useForm({
    internal_code: props.employee?.internal_code ?? '',
    first_name: props.employee?.first_name ?? '',
    last_name: props.employee?.last_name ?? '',
    phone: props.employee?.phone ?? '',
    personal_id_number: props.employee?.personal_id_number ?? '',
    position_id: props.employee?.position_id ?? props.employee?.job_position?.id ?? '',
    profession_skills_text: props.employee?.profession_skills?.join(', ') ?? '',
    profession_skills: [] as string[],
    team_id: props.employee?.team?.id ?? '',
    supervisor_employee_id: props.employee?.supervisor?.id ?? '',
    emergency_contact_name: props.employee?.emergency_contact_name ?? '',
    emergency_contact_phone: props.employee?.emergency_contact_phone ?? '',
    employment_started_at: '',
});

function submit() {
    const payload = {
        ...form.data(),
        profession_skills: form.profession_skills_text
            .split(',')
            .map((skill) => skill.trim())
            .filter(Boolean),
        team_id: form.team_id || null,
        supervisor_employee_id: form.supervisor_employee_id || null,
        position_id: form.position_id || null,
    };

    form.transform(() => payload);

    if (props.mode === 'create') {
        form.post('/employees');
        return;
    }

    form.put(`/employees/${props.employee?.id}`);
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-5 md:grid-cols-2">
            <div v-if="mode === 'create'" class="grid gap-2">
                <Label for="internal_code">შიდა კოდი</Label>
                <Input
                    id="internal_code"
                    v-model="form.internal_code"
                    required
                />
                <InputError :message="form.errors.internal_code" />
            </div>
            <div class="grid gap-2">
                <Label for="first_name">სახელი</Label>
                <Input id="first_name" v-model="form.first_name" required />
                <InputError :message="form.errors.first_name" />
            </div>
            <div class="grid gap-2">
                <Label for="last_name">გვარი</Label>
                <Input id="last_name" v-model="form.last_name" required />
                <InputError :message="form.errors.last_name" />
            </div>
            <div class="grid gap-2">
                <Label for="phone">ტელეფონი</Label>
                <Input id="phone" v-model="form.phone" type="tel" />
                <InputError :message="form.errors.phone" />
            </div>
            <div class="grid gap-2">
                <Label for="personal_id_number">პირადი ნომერი</Label>
                <Input
                    id="personal_id_number"
                    v-model="form.personal_id_number"
                />
                <InputError :message="form.errors.personal_id_number" />
            </div>
            <div class="grid gap-2">
                <Label for="position_id">პოზიცია</Label>
                <select
                    id="position_id"
                    v-model="form.position_id"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="">პოზიციის გარეშე</option>
                    <option v-for="position in positions" :key="position.id" :value="position.id">
                        {{ position.name }}
                    </option>
                </select>
                <p v-if="!positions.length" class="text-muted-foreground text-xs">
                    დამატებული პოზიცია არ არის — დაამატეთ
                    <a href="/positions" class="underline">პოზიციების გვერდზე</a>.
                </p>
                <InputError :message="form.errors.position_id" />
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label for="profession_skills_text">უნარები</Label>
                <Input
                    id="profession_skills_text"
                    v-model="form.profession_skills_text"
                    placeholder="მაგ: ელექტრობა, შედუღება"
                />
                <p class="text-muted-foreground text-xs">გამოყავით მძიმით.</p>
                <InputError :message="form.errors.profession_skills" />
            </div>
            <div class="grid gap-2">
                <Label for="team_id">ბრიგადა</Label>
                <select
                    id="team_id"
                    v-model="form.team_id"
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
                <InputError :message="form.errors.team_id" />
            </div>
            <div class="grid gap-2">
                <Label for="supervisor_employee_id">ხელმძღვანელი</Label>
                <select
                    id="supervisor_employee_id"
                    v-model="form.supervisor_employee_id"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="">არ არის მითითებული</option>
                    <option
                        v-for="supervisor in supervisors"
                        :key="supervisor.id"
                        :value="supervisor.id"
                    >
                        {{ supervisor.first_name }} {{ supervisor.last_name }}
                    </option>
                </select>
                <InputError :message="form.errors.supervisor_employee_id" />
            </div>
            <div class="grid gap-2">
                <Label for="emergency_contact_name">საგანგებო კონტაქტი</Label>
                <Input
                    id="emergency_contact_name"
                    v-model="form.emergency_contact_name"
                />
                <InputError :message="form.errors.emergency_contact_name" />
            </div>
            <div class="grid gap-2">
                <Label for="emergency_contact_phone">საგანგებო ტელეფონი</Label>
                <Input
                    id="emergency_contact_phone"
                    v-model="form.emergency_contact_phone"
                    type="tel"
                />
                <InputError :message="form.errors.emergency_contact_phone" />
            </div>
            <div v-if="mode === 'create'" class="grid gap-2">
                <Label for="employment_started_at">დასაქმების დაწყება</Label>
                <Input
                    id="employment_started_at"
                    v-model="form.employment_started_at"
                    type="date"
                    required
                />
                <InputError :message="form.errors.employment_started_at" />
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <Button as="a" href="/employees" variant="outline">გაუქმება</Button>
            <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'ინახება…' : 'შენახვა' }}
            </Button>
        </div>
    </form>
</template>
