<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import type { StatusTone } from '@/types';

type Member = {
    id: string;
    user_id: string;
    user_name?: string | null;
    user_email?: string | null;
    role_context?: string | null;
};

type LocationRow = {
    id: string;
    parent_location_id?: string | null;
    level_type: string;
    name: string;
};

type DocumentRow = {
    id: string;
    original_filename: string;
    caption?: string | null;
    classification?: string | null;
    byte_size: number;
    uploaded_by?: string | null;
    created_at?: string | null;
};

type ProjectDetail = {
    id: string;
    code: string;
    name: string;
    status: string;
    address?: string | null;
    starts_on?: string | null;
    ends_on?: string | null;
    budget_baseline?: string | null;
    budget_baseline_visible: boolean;
    client?: { id: string; name: string } | null;
    company?: { id: string; name: string } | null;
    manager?: { id: string; name: string } | null;
    version: number;
    updated_at?: string | null;
    can: {
        update: boolean;
        delete: boolean;
        change_status: boolean;
        manage_memberships: boolean;
        manage_wbs: boolean;
        manage_documents: boolean;
        view_budget: boolean;
    };
};

type TaskStats = {
    by_status: Record<string, number>;
    overdue: number;
};

const props = defineProps<{
    project: ProjectDetail;
    statusOptions: string[];
    members: Member[];
    availableUsers: Array<{ id: string; name: string; email: string }>;
    locations: LocationRow[];
    documents: DocumentRow[];
    taskStats: TaskStats;
}>();

defineOptions({ layout: { mobileTitle: 'პროექტი' } });

const STATUS_LABEL: Record<string, string> = {
    planning: 'დაგეგმვა',
    active: 'აქტიური',
    on_hold: 'შეჩერებული',
    completed: 'დასრულებული',
    cancelled: 'გაუქმებული',
};
const STATUS_TONE: Record<string, StatusTone> = {
    planning: 'neutral',
    active: 'success',
    on_hold: 'warning',
    completed: 'info',
    cancelled: 'destructive',
};

const TASK_STATUS_LABEL: Record<string, string> = {
    draft: 'შავი ვარიანტი',
    assigned: 'მინიჭებული',
    in_progress: 'მიმდინარეობს',
    blocked: 'დაბლოკილი',
    submitted: 'გაგზავნილია',
    completed: 'დასრულებული',
    cancelled: 'გაუქმებული',
};
const TASK_STATUS_ORDER = ['draft', 'assigned', 'in_progress', 'blocked', 'submitted', 'completed', 'cancelled'];

type TabKey = 'overview' | 'tasks' | 'members' | 'wbs' | 'documents' | 'activity';
const TABS: Array<{ key: TabKey; label: string }> = [
    { key: 'overview', label: 'მიმოხილვა' },
    { key: 'tasks', label: 'დავალებები' },
    { key: 'members', label: 'წევრები' },
    { key: 'wbs', label: 'სტრუქტურა' },
    { key: 'documents', label: 'დოკუმენტები' },
    { key: 'activity', label: 'აქტივობა' },
];
const activeTab = ref<TabKey>('overview');

const totalTasks = Object.values(props.taskStats.by_status).reduce((sum, count) => sum + count, 0);
const completedTasks = props.taskStats.by_status.completed ?? 0;
const progressPercent = totalTasks > 0 ? Math.round((completedTasks / totalTasks) * 100) : null;

const statusForm = useForm({
    status: '',
    reason: '',
    version: props.project.version,
});

const memberForm = useForm({ user_id: '', role_context: '' });
const locationForm = useForm({ level_type: 'zone', name: '', parent_location_id: '' });
const documentForm = useForm<{ file: File | null; caption: string; classification: string }>({ file: null, caption: '', classification: 'general' });

function changeStatus() {
    if (!statusForm.status) return;
    statusForm.post(`/projects/${props.project.id}/status`, { preserveScroll: true });
}

function addMember() { memberForm.post(`/projects/${props.project.id}/members`, { preserveScroll: true, onSuccess: () => memberForm.reset() }); }
function removeMember(membershipId: string) {
    if (!confirm('ნამდვილად გსურთ ამ წევრის პროექტიდან ამოღება?')) return;
    router.delete(`/projects/${props.project.id}/members/${membershipId}`, { preserveScroll: true });
}
function addLocation() { locationForm.post(`/projects/${props.project.id}/locations`, { preserveScroll: true, onSuccess: () => locationForm.reset('name', 'parent_location_id') }); }
function removeLocation(locationId: string) {
    if (!confirm('ნამდვილად გსურთ ამ ელემენტის წაშლა სტრუქტურიდან?')) return;
    router.delete(`/projects/${props.project.id}/locations/${locationId}`, { preserveScroll: true });
}
function uploadDocument() { documentForm.post(`/projects/${props.project.id}/documents`, { forceFormData: true, preserveScroll: true, onSuccess: () => documentForm.reset() }); }
function removeDocument(documentId: string) {
    if (!confirm('ნამდვილად გსურთ დოკუმენტის წაშლა?')) return;
    router.delete(`/projects/${props.project.id}/documents/${documentId}`, { preserveScroll: true });
}
function handleFile(event: Event) { documentForm.file = (event.target as HTMLInputElement).files?.[0] || null; }

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <Head :title="project.name" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link href="/projects" class="text-muted-foreground text-sm hover:underline">← პროექტები</Link>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ project.name }}</h1>
                    <StatusBadge :label="STATUS_LABEL[project.status] || project.status" :tone="STATUS_TONE[project.status] || 'neutral'" />
                </div>
                <p class="text-muted-foreground text-sm">
                    {{ project.code }} · {{ project.company?.name || 'კომპანია მიუთითებელია' }} · {{ project.client?.name || 'კლიენტი მიუთითებელია' }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button as-child variant="secondary"><Link :href="`/projects/${project.id}/tasks`">დავალებების სრული სია</Link></Button>
                <Button v-if="project.can.update" as-child variant="outline"><Link :href="`/projects/${project.id}/edit`">რედაქტირება</Link></Button>
            </div>
        </div>

        <nav class="border-border flex gap-1 overflow-x-auto border-b">
            <button
                v-for="tab in TABS"
                :key="tab.key"
                type="button"
                class="shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-colors"
                :class="activeTab === tab.key ? 'border-primary text-foreground' : 'text-muted-foreground border-transparent hover:text-foreground'"
                @click="activeTab = tab.key"
            >
                {{ tab.label }}
                <span v-if="tab.key === 'tasks' && taskStats.overdue > 0" class="bg-destructive/15 text-destructive ml-1 rounded-full px-1.5 py-0.5 text-xs">
                    {{ taskStats.overdue }}
                </span>
            </button>
        </nav>

        <div v-if="activeTab === 'overview'" class="grid gap-5 lg:grid-cols-3">
            <section class="border-border bg-card rounded-xl border p-5 lg:col-span-2">
                <h2 class="font-semibold">ძირითადი ინფორმაცია</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted-foreground">კომპანია</dt><dd>{{ project.company?.name || '—' }}</dd></div>
                    <div><dt class="text-muted-foreground">კლიენტი</dt><dd>{{ project.client?.name || '—' }}</dd></div>
                    <div><dt class="text-muted-foreground">მენეჯერი</dt><dd>{{ project.manager?.name || '—' }}</dd></div>
                    <div><dt class="text-muted-foreground">მისამართი</dt><dd>{{ project.address || '—' }}</dd></div>
                    <div><dt class="text-muted-foreground">დაწყება</dt><dd>{{ project.starts_on || '—' }}</dd></div>
                    <div><dt class="text-muted-foreground">დასრულება</dt><dd>{{ project.ends_on || '—' }}</dd></div>
                    <div v-if="project.budget_baseline_visible">
                        <dt class="text-muted-foreground">ბიუჯეტის საწყისი მაჩვენებელი</dt>
                        <dd>{{ project.budget_baseline || '0.00' }} GEL</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">პროგრესი</dt>
                        <dd v-if="progressPercent !== null">{{ progressPercent }}% ({{ completedTasks }}/{{ totalTasks }} დავალება დასრულებული)</dd>
                        <dd v-else class="text-muted-foreground">დავალებები ჯერ არ არის</dd>
                    </div>
                    <div v-if="project.updated_at">
                        <dt class="text-muted-foreground">ბოლო განახლება</dt>
                        <dd>{{ new Date(project.updated_at).toLocaleString('ka-GE') }} · ვერსია {{ project.version }}</dd>
                    </div>
                </dl>
            </section>

            <section v-if="project.can.change_status" class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">სტატუსის შეცვლა</h2>
                <form class="mt-4 grid gap-3" @submit.prevent="changeStatus">
                    <select v-model="statusForm.status" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="" disabled>აირჩიეთ ახალი სტატუსი</option>
                        <option v-for="option in statusOptions" :key="option" :value="option">{{ STATUS_LABEL[option] || option }}</option>
                    </select>
                    <Input v-model="statusForm.reason" placeholder="მიზეზი (საჭიროების შემთხვევაში)" />
                    <p v-if="statusForm.errors.status" class="text-destructive text-sm">{{ statusForm.errors.status }}</p>
                    <p v-if="statusForm.errors.version" class="border-warning bg-warning/10 rounded-lg border p-2 text-sm">
                        პროექტი შეიცვალა სხვის მიერ — გთხოვთ განაახლოთ გვერდი.
                    </p>
                    <Button type="submit" size="sm" :disabled="statusForm.processing || !statusForm.status">განახლება</Button>
                </form>
            </section>
        </div>

        <div v-else-if="activeTab === 'tasks'" class="flex flex-col gap-4">
            <!-- Audit A07: these counts used to be inert text next to one
                 link. Each now opens the workspace already filtered to the
                 status whose number was clicked, so the figure on the card
                 and the rows behind it are the same query. -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Link
                    v-for="status in TASK_STATUS_ORDER"
                    :key="status"
                    :href="`/projects/${project.id}/tasks?status=${status}`"
                    class="border-border bg-card hover:bg-muted/40 rounded-xl border p-4 transition-colors"
                >
                    <p class="text-muted-foreground text-xs">{{ TASK_STATUS_LABEL[status] }}</p>
                    <p class="mt-1 text-xl font-semibold">{{ taskStats.by_status[status] ?? 0 }}</p>
                </Link>
            </div>
            <div v-if="taskStats.overdue > 0" class="border-destructive/30 bg-destructive-soft/30 rounded-xl border p-4 text-sm">
                {{ taskStats.overdue }} ვადაგადაცილებული დავალება მოითხოვს ყურადღებას.
            </div>
            <EmptyState v-if="totalTasks === 0" title="დავალება ჯერ არ არის" description="დაამატეთ პირველი დავალება ამ პროექტზე." />
            <div class="flex flex-wrap gap-2">
                <Button as-child><Link :href="`/projects/${project.id}/tasks`">დავალებების სია</Link></Button>
                <Button as-child variant="outline"><Link :href="`/projects/${project.id}/tasks?view=kanban`">Kanban დაფა</Link></Button>
            </div>
        </div>

        <div v-else-if="activeTab === 'members'" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">პროექტის წევრები</h2>
            <div class="mt-3 space-y-2 text-sm">
                <div v-for="member in members" :key="member.id" class="flex items-center justify-between rounded-lg border p-3">
                    <span>{{ member.user_name || member.user_email || member.user_id }}</span>
                    <div class="flex items-center gap-2">
                        <span class="text-muted-foreground">{{ member.role_context || 'წევრი' }}</span>
                        <Button v-if="project.can.manage_memberships" size="sm" variant="ghost" class="text-destructive" @click="removeMember(member.id)">ამოღება</Button>
                    </div>
                </div>
                <EmptyState v-if="!members.length" title="წევრი ჯერ არ არის დამატებული" description="დაამატეთ ამ პროექტზე პასუხისმგებელი ადამიანები." />
            </div>
            <form v-if="project.can.manage_memberships && availableUsers.length" class="mt-4 flex flex-wrap gap-2" @submit.prevent="addMember">
                <select v-model="memberForm.user_id" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option value="" disabled>აირჩიეთ მომხმარებელი</option>
                    <option v-for="user in availableUsers" :key="user.id" :value="user.id">{{ user.name }} ({{ user.email }})</option>
                </select>
                <Input v-model="memberForm.role_context" placeholder="როლი პროექტში" class="w-40" />
                <Button size="sm" type="submit" :disabled="memberForm.processing">წევრის დამატება</Button>
            </form>
            <p v-else-if="project.can.manage_memberships" class="text-muted-foreground mt-4 text-sm">დასამატებელი მომხმარებელი არ არის.</p>
            <p v-else class="text-muted-foreground mt-4 text-sm">წევრების მართვის უფლება არ გაქვთ.</p>
        </div>

        <div v-else-if="activeTab === 'wbs'" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">სამუშაოთა სტრუქტურა (WBS)</h2>
            <div class="mt-3 space-y-2 text-sm">
                <div v-for="location in locations" :key="location.id" class="flex items-center justify-between rounded-lg border p-2">
                    <span><span class="text-muted-foreground">{{ location.level_type }}</span> · {{ location.name }}</span>
                    <Button v-if="project.can.manage_wbs" size="sm" variant="ghost" class="text-destructive" @click="removeLocation(location.id)">წაშლა</Button>
                </div>
                <EmptyState v-if="!locations.length" title="სტრუქტურის ელემენტი ჯერ არ არის" description="საჭიროების შემთხვევაში დაამატეთ ზონები, სართულები ან სივრცეები." />
            </div>
            <form v-if="project.can.manage_wbs" class="mt-4 grid gap-2 sm:grid-cols-[1fr_auto_auto]" @submit.prevent="addLocation">
                <Input v-model="locationForm.name" required placeholder="ახალი ელემენტის სახელი" />
                <select v-model="locationForm.level_type" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option value="site">საიტი</option>
                    <option value="building">კორპუსი</option>
                    <option value="zone">ზონა</option>
                    <option value="floor">სართული</option>
                </select>
                <Button size="sm" type="submit" :disabled="locationForm.processing">დამატება</Button>
            </form>
        </div>

        <div v-else-if="activeTab === 'documents'" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">პროექტის დოკუმენტები</h2>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <div v-for="document in documents" :key="document.id" class="rounded-lg border p-3 text-sm">
                    <a :href="`/projects/${project.id}/documents/${document.id}`" class="font-medium hover:underline">{{ document.original_filename }}</a>
                    <p class="text-muted-foreground text-xs">
                        {{ document.caption || document.classification || 'ზოგადი' }} · {{ formatBytes(document.byte_size) }}
                        <span v-if="document.uploaded_by"> · {{ document.uploaded_by }}</span>
                        <span v-if="document.created_at"> · {{ new Date(document.created_at).toLocaleDateString('ka-GE') }}</span>
                    </p>
                    <Button v-if="project.can.manage_documents" size="sm" variant="ghost" class="text-destructive mt-1 px-0" @click="removeDocument(document.id)">წაშლა</Button>
                </div>
            </div>
            <EmptyState v-if="!documents.length" title="დოკუმენტი ჯერ არ არის ატვირთული" description="ატვირთეთ ნახაზები, კონტრაქტები ან სხვა დოკუმენტები." />
            <form v-if="project.can.manage_documents" class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="uploadDocument">
                <div><Label>ფაილი</Label><Input required type="file" @change="handleFile" /></div>
                <Input v-model="documentForm.caption" placeholder="წარწერა" />
                <Button size="sm" type="submit" :disabled="documentForm.processing || !documentForm.file">ატვირთვა</Button>
                <p v-if="documentForm.errors.file" class="text-destructive w-full text-sm">{{ documentForm.errors.file }}</p>
            </form>
        </div>

        <div v-else-if="activeTab === 'activity'" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">აქტივობა</h2>
            <EmptyState class="mt-3" title="აქტივობის ისტორია ჯერ არ არის ხელმისაწვდომი" description="პროექტის დონეზე აქტივობის ჟურნალი ჯერ არ არის დანერგილი." />
        </div>
    </div>
</template>
