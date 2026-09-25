<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import TaskKanban from '@/components/tasks/TaskKanban.vue';
import type { StatusTone } from '@/types';

type Task = {
    id: string;
    project_id: string;
    title: string;
    status: string;
    priority: string;
    due_at?: string | null;
    unit?: string | null;
    planned_quantity?: string | null;
    accepted_quantity?: string | null;
    accountable_owner?: { id: string; full_name: string } | null;
};

const props = defineProps<{
    project: { id: string; name: string; code: string };
    view: 'list' | 'kanban';
    tasks: Task[];
    pagination: { page: number; perPage: number; total: number } | null;
    kanbanTruncated: boolean;
    kanbanLimit: number;
    filters: { status: string; accountable_owner_employee_id: string; priority: string };
    employees: Array<{ id: string; full_name: string }>;
    canCreate: boolean;
}>();

// Audit A07: the project page linked here promising „სია და Kanban" while
// this screen only ever rendered a list. The board is the same component the
// dashboard uses, so both agree on what each drag means.
const filterQuery = computed(() => {
    const params = new URLSearchParams();
    if (props.filters.status) params.set('status', props.filters.status);
    if (props.filters.priority) params.set('priority', props.filters.priority);
    if (props.filters.accountable_owner_employee_id) {
        params.set('accountable_owner_employee_id', props.filters.accountable_owner_employee_id);
    }

    return params;
});

function viewHref(view: 'list' | 'kanban'): string {
    const params = new URLSearchParams(filterQuery.value);
    if (view !== 'list') params.set('view', view);
    const query = params.toString();

    return `/projects/${props.project.id}/tasks${query ? `?${query}` : ''}`;
}

const hasActiveFilters = props.filters.status !== '' || props.filters.accountable_owner_employee_id !== '' || props.filters.priority !== '';

defineOptions({ layout: { mobileTitle: 'დავალებები' } });

const STATUS_LABEL: Record<string, string> = {
    draft: 'შავი ვარიანტი',
    assigned: 'მინიჭებული',
    in_progress: 'მიმდინარეობს',
    blocked: 'დაბლოკილი',
    submitted: 'გაგზავნილია',
    completed: 'დასრულებული',
    cancelled: 'გაუქმებული',
};
const STATUS_TONE: Record<string, StatusTone> = {
    draft: 'neutral',
    assigned: 'info',
    in_progress: 'info',
    blocked: 'warning',
    submitted: 'warning',
    completed: 'success',
    cancelled: 'destructive',
};
const PRIORITY_LABEL: Record<string, string> = { low: 'დაბალი', normal: 'ჩვეულებრივი', high: 'მაღალი', urgent: 'გადაუდებელი' };

const lastPage = props.pagination ? Math.max(1, Math.ceil(props.pagination.total / props.pagination.perPage)) : 1;
</script>

<template>
    <Head :title="`დავალებები — ${project.name}`" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Link :href="`/projects/${project.id}`" class="text-muted-foreground text-sm hover:underline">← {{ project.name }}</Link>
                <h1 class="mt-2 text-2xl font-semibold">დავალებები</h1>
            </div>
            <div class="flex items-center gap-2">
                <div class="border-border inline-flex overflow-hidden rounded-lg border text-sm">
                    <Link
                        :href="viewHref('list')"
                        :class="['px-3 py-1.5', view === 'list' ? 'bg-accent text-accent-foreground font-medium' : 'text-muted-foreground hover:bg-muted/50']"
                        >სია</Link
                    >
                    <Link
                        :href="viewHref('kanban')"
                        :class="['border-border border-l px-3 py-1.5', view === 'kanban' ? 'bg-accent text-accent-foreground font-medium' : 'text-muted-foreground hover:bg-muted/50']"
                        >Kanban</Link
                    >
                </div>
                <Button v-if="canCreate" as-child><Link :href="`/projects/${project.id}/tasks/create`">დავალების დამატება</Link></Button>
            </div>
        </div>

        <form method="get" :action="`/projects/${project.id}/tasks`" class="border-border bg-card grid gap-3 rounded-xl border p-4 md:grid-cols-4">
            <!-- Filtering must not silently throw the operator back to the
                 list they just switched away from. -->
            <input v-if="view !== 'list'" type="hidden" name="view" :value="view" />
            <select name="status" :value="filters.status" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                <option value="">ყველა სტატუსი</option>
                <option value="draft">შავი ვარიანტი</option>
                <option value="assigned">მინიჭებული</option>
                <option value="in_progress">მიმდინარეობს</option>
                <option value="blocked">დაბლოკილი</option>
                <option value="submitted">გაგზავნილია</option>
                <option value="completed">დასრულებული</option>
                <option value="cancelled">გაუქმებული</option>
            </select>
            <select name="priority" :value="filters.priority" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                <option value="">ყველა პრიორიტეტი</option>
                <option value="low">დაბალი</option>
                <option value="normal">ჩვეულებრივი</option>
                <option value="high">მაღალი</option>
                <option value="urgent">გადაუდებელი</option>
            </select>
            <select name="accountable_owner_employee_id" :value="filters.accountable_owner_employee_id" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                <option value="">ყველა პასუხისმგებელი</option>
                <option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.full_name }}</option>
            </select>
            <div class="flex gap-2">
                <Button type="submit" variant="outline">ფილტრი</Button>
                <Button v-if="hasActiveFilters" as-child variant="ghost"><Link :href="`/projects/${project.id}/tasks`">გასუფთავება</Link></Button>
            </div>
        </form>

        <template v-if="view === 'kanban'">
            <p v-if="kanbanTruncated" class="border-warning bg-warning/10 rounded-lg border px-3 py-2 text-sm">
                დაფაზე ნაჩვენებია პირველი {{ kanbanLimit }} დავალება. დანარჩენის სანახავად დააზუსტეთ ფილტრი ან გადადით სიაში.
            </p>
            <EmptyState
                v-if="tasks.length === 0"
                :title="hasActiveFilters ? 'ფილტრით დავალება ვერ მოიძებნა' : 'დავალება ვერ მოიძებნა'"
                :description="hasActiveFilters ? 'სცადეთ ფილტრების შეცვლა ან გასუფთავება.' : 'ამ პროექტზე ჯერ არცერთი დავალება არ არის.'"
            />
            <TaskKanban v-else :tasks="tasks" :show-project-name="false" />
        </template>

        <template v-else>
        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="tasks.length === 0"
                :title="hasActiveFilters ? 'ფილტრით დავალება ვერ მოიძებნა' : 'დავალება ვერ მოიძებნა'"
                :description="hasActiveFilters ? 'სცადეთ ფილტრების შეცვლა ან გასუფთავება.' : 'ამ პროექტზე ჯერ არცერთი დავალება არ არის.'"
            />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="task in tasks"
                    :key="task.id"
                    :href="`/projects/${project.id}/tasks/${task.id}`"
                    class="hover:bg-muted/40 grid gap-2 p-4 transition-colors md:grid-cols-[1fr_160px_140px_120px] md:items-center"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ task.title }}</p>
                        <p class="text-muted-foreground truncate text-sm">
                            {{ task.accountable_owner?.full_name || 'პასუხისმგებელი მიუთითებელია' }}
                        </p>
                    </div>
                    <p class="text-muted-foreground text-sm">{{ task.due_at ? new Date(task.due_at).toLocaleDateString('ka-GE') : 'ვადის გარეშე' }}</p>
                    <StatusBadge :label="STATUS_LABEL[task.status] || task.status" :tone="STATUS_TONE[task.status] || 'neutral'" />
                    <p class="text-muted-foreground text-sm">{{ PRIORITY_LABEL[task.priority] || task.priority }}</p>
                </Link>
            </div>
        </div>
        </template>

        <div v-if="pagination" class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground">გვერდი {{ pagination.page }} / {{ lastPage }} · სულ {{ pagination.total }}</span>
        </div>
    </div>
</template>
