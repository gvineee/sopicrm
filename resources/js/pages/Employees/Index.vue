<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Employee = {
    id: string;
    internal_code: string;
    full_name: string;
    phone?: string | null;
    position?: string | null;
    job_position?: { id: string; name: string } | null;
    status: string;
    team_name?: string | null;
};

type PaginatedEmployees = {
    data: Employee[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

defineOptions({ layout: { mobileTitle: 'თანამშრომლები' } });

const props = defineProps<{
    employees: PaginatedEmployees;
    filters: { search?: string; status?: string; team_id?: string; position_id?: string; supervisor_employee_id?: string };
    teams: Array<{ id: string; name: string }>;
    positions: Array<{ id: string; name: string }>;
    supervisors: Array<{ id: string; first_name: string; last_name: string }>;
}>();

const hasActiveFilters = Boolean(
    props.filters.search || props.filters.status || props.filters.team_id || props.filters.position_id || props.filters.supervisor_employee_id,
);
</script>

<template>
    <Head title="თანამშრომლები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">თანამშრომლები</h1>
                <p class="text-muted-foreground text-sm">
                    პროფილები, გუნდები და დასაქმების სტატუსი.
                </p>
            </div>
            <Button as-child
                ><Link href="/employees/create"
                    >თანამშრომლის დამატება</Link
                ></Button
            >
        </div>

        <form
            method="get"
            action="/employees"
            class="border-border bg-card grid gap-3 rounded-xl border p-4 md:grid-cols-5"
        >
            <Input
                name="search"
                :default-value="filters.search"
                placeholder="სახელი ან შიდა კოდი"
                class="md:col-span-2"
            />
            <select
                name="status"
                :value="filters.status"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">ყველა სტატუსი</option>
                <option value="active">აქტიური</option>
                <option value="terminated">დასრულებული</option>
            </select>
            <select
                name="team_id"
                :value="filters.team_id"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">ყველა ბრიგადა</option>
                <option
                    v-for="team in teams"
                    :key="team.id"
                    :value="team.id"
                >
                    {{ team.name }}
                </option>
            </select>
            <select
                name="position_id"
                :value="filters.position_id"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">ყველა პოზიცია</option>
                <option v-for="position in positions" :key="position.id" :value="position.id">
                    {{ position.name }}
                </option>
            </select>
            <div class="flex gap-2 md:col-span-5">
                <select
                    name="supervisor_employee_id"
                    :value="filters.supervisor_employee_id"
                    class="border-input bg-background h-9 min-w-0 flex-1 rounded-md border px-3 text-sm"
                >
                    <option value="">ყველა ხელმძღვანელი</option>
                    <option v-for="supervisor in supervisors" :key="supervisor.id" :value="supervisor.id">
                        {{ supervisor.first_name }} {{ supervisor.last_name }}
                    </option>
                </select>
                <Button type="submit" variant="outline">ძიება</Button>
                <Button v-if="hasActiveFilters" as-child variant="ghost"><Link href="/employees">გასუფთავება</Link></Button>
            </div>
        </form>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <div
                v-if="employees.data.length === 0"
                class="text-muted-foreground p-10 text-center text-sm"
            >
                ჩანაწერები ვერ მოიძებნა.
            </div>
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="employee in employees.data"
                    :key="employee.id"
                    :href="`/employees/${employee.id}`"
                    class="hover:bg-muted/40 grid gap-1 p-4 transition-colors md:grid-cols-[1fr_180px_160px] md:items-center"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ employee.full_name }}
                        </p>
                        <p class="text-muted-foreground text-sm">
                            {{ employee.internal_code }} ·
                            {{
                                employee.job_position?.name ||
                                employee.position ||
                                'პოზიცია არ არის მითითებული'
                            }}
                        </p>
                    </div>
                    <p class="text-muted-foreground text-sm">
                        {{ employee.team_name || 'ბრიგადის გარეშე' }}
                    </p>
                    <p class="text-sm md:text-right">
                        {{
                            employee.status === 'active'
                                ? 'აქტიური'
                                : 'დასრულებული'
                        }}
                    </p>
                </Link>
            </div>
        </div>

        <div class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground"
                >გვერდი {{ employees.current_page }} /
                {{ employees.last_page }}</span
            >
            <div class="flex gap-2">
                <Button
                    v-if="employees.prev_page_url"
                    as-child
                    variant="outline"
                    size="sm"
                    ><Link :href="employees.prev_page_url">წინა</Link></Button
                >
                <Button
                    v-if="employees.next_page_url"
                    as-child
                    variant="outline"
                    size="sm"
                    ><Link :href="employees.next_page_url"
                        >შემდეგი</Link
                    ></Button
                >
            </div>
        </div>
    </div>
</template>
