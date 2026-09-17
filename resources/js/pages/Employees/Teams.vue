<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Employee = { id: string; first_name: string; last_name: string };
type Team = {
    id: string;
    name: string;
    is_active: boolean;
    members_count: number;
    foreman?: Employee | null;
};

defineOptions({ layout: { mobileTitle: 'ბრიგადები' } });
defineProps<{ teams: Team[]; employees: Employee[] }>();

const form = useForm({ name: '', foreman_employee_id: '' });

function changeForeman(team: Team, event: Event) {
    const target = event.target as HTMLSelectElement;
    router.patch(
        `/teams/${team.id}`,
        { foreman_employee_id: target.value || null },
        { preserveScroll: true },
    );
}

function toggleTeam(team: Team) {
    router.patch(
        `/teams/${team.id}`,
        { is_active: !team.is_active },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="ბრიგადები" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">ბრიგადები</h1>
            <p class="text-muted-foreground text-sm">
                გუნდები, ბრიგადირები და აქტიური წევრები.
            </p>
        </div>
        <form
            class="border-border bg-card grid gap-3 rounded-xl border p-5 md:grid-cols-[1fr_1fr_auto]"
            @submit.prevent="
                form
                    .transform((data) => ({
                        ...data,
                        foreman_employee_id: data.foreman_employee_id || null,
                    }))
                    .post('/teams', { onSuccess: () => form.reset() })
            "
        >
            <Input v-model="form.name" placeholder="ბრიგადის სახელი" required />
            <select
                v-model="form.foreman_employee_id"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">ბრიგადირის გარეშე</option>
                <option
                    v-for="employee in employees"
                    :key="employee.id"
                    :value="employee.id"
                >
                    {{ employee.first_name }} {{ employee.last_name }}
                </option>
            </select>
            <Button type="submit" :disabled="form.processing">დამატება</Button>
        </form>
        <div class="grid gap-3 md:grid-cols-2">
            <article
                v-for="team in teams"
                :key="team.id"
                class="border-border bg-card rounded-xl border p-5"
            >
                <div class="flex items-start justify-between gap-3">
                    <h2 class="font-semibold">{{ team.name }}</h2>
                    <span class="text-muted-foreground text-xs">{{
                        team.is_active ? 'აქტიური' : 'არააქტიური'
                    }}</span>
                </div>
                <p class="text-muted-foreground mt-2 text-sm">
                    ბრიგადირი:
                    {{
                        team.foreman
                            ? `${team.foreman.first_name} ${team.foreman.last_name}`
                            : 'არ არის'
                    }}
                </p>
                <p class="text-muted-foreground text-sm">
                    წევრები: {{ team.members_count }}
                </p>
                <div class="mt-4 flex gap-2">
                    <select
                        :value="team.foreman?.id || ''"
                        class="border-input bg-background h-9 min-w-0 flex-1 rounded-md border px-3 text-sm"
                        @change="changeForeman(team, $event)"
                    >
                        <option value="">ბრიგადირის გარეშე</option>
                        <option
                            v-for="employee in employees"
                            :key="employee.id"
                            :value="employee.id"
                        >
                            {{ employee.first_name }} {{ employee.last_name }}
                        </option>
                    </select>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="toggleTeam(team)"
                    >
                        {{ team.is_active ? 'გამორთვა' : 'გააქტიურება' }}
                    </Button>
                </div>
            </article>
        </div>
    </div>
</template>
