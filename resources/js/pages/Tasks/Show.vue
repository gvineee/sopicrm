<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { attachmentClassificationLabel, attachmentStatusLabel, submissionStatusLabel } from '@/lib/labels';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import type { StatusTone } from '@/types';

type Comment = {
    id: string;
    body: string;
    author_name?: string | null;
    created_at: string;
    replies: Comment[];
};

type TaskAttachment = {
    id: string;
    original_filename: string;
    mime_type?: string | null;
    caption?: string | null;
    classification?: string | null;
    status: string;
    url?: string | null;
    is_photo?: boolean;
    selectable_as_evidence?: boolean;
};

type Submission = {
    id: string;
    status: string;
    submitted_quantity?: string | null;
    comment?: string | null;
    submitted_at?: string | null;
    returned_reason?: string | null;
    submitted_by?: string | null;
    acceptance?: { accepted_quantity: string; accepted_at: string; notes?: string | null } | null;
    photos: TaskAttachment[];
    version: number;
    can: { accept: boolean; return: boolean };
};

type TaskDetail = {
    id: string;
    project_id: string;
    title: string;
    description?: string | null;
    status: string;
    priority: string;
    due_at?: string | null;
    unit?: string | null;
    planned_quantity?: string | null;
    accepted_quantity?: string | null;
    remaining_quantity?: string | null;
    legacy_acceptance_unverified?: boolean;
    requires_photo_evidence?: boolean;
    min_required_photos?: number;
    version: number;
    blocked_reason?: string | null;
    cancelled_reason?: string | null;
    reopened_reason?: string | null;
    accountable_owner?: { id: string; full_name: string } | null;
    assignees: Array<{ id: string; employee_name?: string | null; team_name?: string | null }>;
    checklist_items: Array<{ id: string; label: string; is_required: boolean; is_checked: boolean }>;
    dependencies: Array<{ id: string; depends_on_task_id: string; depends_on_title?: string | null; depends_on_status?: string | null }>;
    submissions: Submission[];
    attachments: TaskAttachment[];
    comments: Comment[];
    status_events: Array<{ id: string; from_status?: string | null; to_status: string; reason?: string | null; occurred_at: string; actor_name?: string | null }>;
    can: {
        update: boolean;
        assign: boolean;
        start: boolean;
        block: boolean;
        unblock: boolean;
        submit: boolean;
        cancel: boolean;
        reopen: boolean;
        manage_dependencies: boolean;
        upload_attachment: boolean;
        comment: boolean;
    };
};

const props = defineProps<{
    project: { id: string; name: string };
    task: TaskDetail;
}>();

defineOptions({ layout: { mobileTitle: 'დავალება' } });

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
    draft: 'neutral', assigned: 'info', in_progress: 'info', blocked: 'warning',
    submitted: 'warning', completed: 'success', cancelled: 'destructive',
};

const base = `/projects/${props.project.id}/tasks/${props.task.id}`;

const blockForm = useForm({ reason: '', blocked_owner_employee_id: props.task.accountable_owner?.id ?? '' });
const unblockForm = useForm({ reason: '' });
const cancelForm = useForm({ reason: '' });
const reopenForm = useForm({ reason: '' });
// TM-03: the page uploaded evidence but never told the server which files
// the performer was offering, so `attachment_ids` always arrived empty and a
// photo-required task could not be submitted from the web UI at all.
const submitForm = useForm<{ comment: string; submitted_quantity: string; attachment_ids: string[] }>({
    comment: '',
    submitted_quantity: '',
    attachment_ids: [],
});
const commentForm = useForm({ body: '' });
const uploadForm = useForm<{ file: File | null; classification: string; caption: string }>({ file: null, classification: 'other', caption: '' });

const showBlock = reactive({ open: false });
const showCancel = reactive({ open: false });
const showReopen = reactive({ open: false });

function simplePost(url: string) {
    router.post(url, {}, { preserveScroll: true });
}

const acceptForms = reactive<Record<string, ReturnType<typeof useForm<{ accepted_quantity: string; notes: string }>>>>({});
const returnForms = reactive<Record<string, ReturnType<typeof useForm<{ reason: string }>>>>({});
const openReturn = reactive<Record<string, boolean>>({});

function acceptFormFor(id: string) {
    if (!acceptForms[id]) acceptForms[id] = useForm({ accepted_quantity: '', notes: '' });
    return acceptForms[id];
}
function returnFormFor(id: string) {
    if (!returnForms[id]) returnForms[id] = useForm({ reason: '' });
    return returnForms[id];
}

// The submission version travels with the decision so a reviewer acting on a
// stale page loses to whoever decided first, instead of silently overwriting
// them (spec §13.2).
function acceptSubmission(submission: Submission) {
    acceptFormFor(submission.id)
        .transform((d) => ({
            ...d,
            accepted_quantity: d.accepted_quantity || null,
            expected_version: submission.version,
        }))
        .post(`${base}/submissions/${submission.id}/accept`, { preserveScroll: true });
}
function submitReturn(submission: Submission) {
    returnFormFor(submission.id)
        .transform((d) => ({ ...d, expected_version: submission.version }))
        .post(`${base}/submissions/${submission.id}/return`, {
            preserveScroll: true,
            onSuccess: () => { openReturn[submission.id] = false; },
        });
}

const checklistForms = reactive<Record<string, boolean>>({});
function toggleChecklist(itemId: string, current: boolean) {
    checklistForms[itemId] = true;
    router.patch(`${base}/checklist-items/${itemId}`, { is_checked: !current }, {
        preserveScroll: true,
        onFinish: () => { checklistForms[itemId] = false; },
    });
}

function selectFile(event: Event) {
    const target = event.target as HTMLInputElement;
    uploadForm.file = target.files?.[0] ?? null;
}
function uploadAttachment() {
    uploadForm.post(`${base}/attachments`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    });
}
const selectableEvidence = computed(() => props.task.attachments.filter((a) => a.selectable_as_evidence));
const selectedPhotoCount = computed(
    () => selectableEvidence.value.filter((a) => a.is_photo && submitForm.attachment_ids.includes(a.id)).length,
);
const missingPhotoCount = computed(() =>
    props.task.requires_photo_evidence
        ? Math.max(0, (props.task.min_required_photos ?? 0) - selectedPhotoCount.value)
        : 0,
);

function submitTask() {
    submitForm
        .transform((d) => ({
            ...d,
            submitted_quantity: d.submitted_quantity || null,
            expected_version: props.task.version,
        }))
        .post(`${base}/submit`, { preserveScroll: true });
}
function addComment() {
    commentForm.post(`${base}/comments`, { preserveScroll: true, onSuccess: () => commentForm.reset() });
}
</script>

<template>
    <Head :title="task.title" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link :href="`/projects/${project.id}/tasks`" class="text-muted-foreground text-sm hover:underline">← დავალებები</Link>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ task.title }}</h1>
                    <StatusBadge :label="STATUS_LABEL[task.status] || task.status" :tone="STATUS_TONE[task.status] || 'neutral'" />
                </div>
                <p class="text-muted-foreground text-sm">{{ project.name }} · {{ task.accountable_owner?.full_name || 'პასუხისმგებელი მიუთითებელია' }}</p>
            </div>
            <Button v-if="task.can.update" as-child variant="outline"><Link :href="`${base}/edit`">რედაქტირება</Link></Button>
        </div>

        <div v-if="task.blocked_reason" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
            დაბლოკილია: {{ task.blocked_reason }}
        </div>
        <div v-if="task.cancelled_reason" class="border-destructive/30 bg-destructive-soft/30 rounded-xl border p-4 text-sm">
            გაუქმებულია: {{ task.cancelled_reason }}
        </div>
        <!-- Spec §17: an old closure that cannot show two independent
             confirmations keeps its status but is never presented as if it
             met the current rule. -->
        <div v-if="task.legacy_acceptance_unverified" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
            ისტორიული ჩანაწერი — ახალი წესით ვერიფიკაცია არ არის დადასტურებული.
        </div>

        <div class="flex flex-wrap gap-2">
            <Button v-if="task.can.assign" size="sm" @click="simplePost(`${base}/assign`)">მინიჭება</Button>
            <Button v-if="task.can.start" size="sm" @click="simplePost(`${base}/start`)">დაწყება</Button>
            <Button v-if="task.can.block" size="sm" variant="outline" @click="showBlock.open = !showBlock.open">დაბლოკვა</Button>
            <Button v-if="task.can.unblock" size="sm" variant="outline" @click="simplePost(`${base}/unblock`)">განბლოკვა</Button>
            <Button v-if="task.can.cancel" size="sm" variant="outline" class="border-destructive text-destructive" @click="showCancel.open = !showCancel.open">გაუქმება</Button>
            <Button v-if="task.can.reopen" size="sm" variant="outline" @click="showReopen.open = !showReopen.open">ხელახლა გახსნა</Button>
        </div>

        <form v-if="showBlock.open" class="border-border bg-card grid gap-2 rounded-xl border p-4 md:grid-cols-2" @submit.prevent="blockForm.post(`${base}/block`, { preserveScroll: true, onSuccess: () => (showBlock.open = false) })">
            <Input v-model="blockForm.reason" placeholder="დაბლოკვის მიზეზი" required />
            <Input v-model="blockForm.blocked_owner_employee_id" placeholder="მოხსნის პასუხისმგებელი employee ID" required />
            <Button type="submit" size="sm" class="w-fit md:col-span-2" :disabled="blockForm.processing">დადასტურება</Button>
        </form>
        <form v-if="showCancel.open" class="border-destructive/30 grid gap-2 rounded-xl border p-4" @submit.prevent="cancelForm.post(`${base}/cancel`, { preserveScroll: true, onSuccess: () => (showCancel.open = false) })">
            <Input v-model="cancelForm.reason" placeholder="გაუქმების მიზეზი" required />
            <Button type="submit" size="sm" variant="destructive" class="w-fit" :disabled="cancelForm.processing">დადასტურება</Button>
        </form>
        <form v-if="showReopen.open" class="border-border bg-card grid gap-2 rounded-xl border p-4" @submit.prevent="reopenForm.post(`${base}/reopen`, { preserveScroll: true, onSuccess: () => (showReopen.open = false) })">
            <Input v-model="reopenForm.reason" placeholder="ხელახლა გახსნის მიზეზი" required />
            <Button type="submit" size="sm" class="w-fit" :disabled="reopenForm.processing">დადასტურება</Button>
        </form>

        <div class="grid gap-5 lg:grid-cols-3">
            <section class="border-border bg-card rounded-xl border p-5 lg:col-span-2">
                <h2 class="font-semibold">აღწერა</h2>
                <p class="text-muted-foreground mt-2 text-sm whitespace-pre-line">{{ task.description || 'აღწერა არ არის მითითებული.' }}</p>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted-foreground">ვადა</dt><dd>{{ task.due_at ? new Date(task.due_at).toLocaleString('ka-GE') : '—' }}</dd></div>
                    <!-- Audit A19: this read „0.00 / — 20" when someone had
                         typed the number into the unit field and left the
                         planned quantity empty. A task with no planned volume
                         is a yes/no task, so it now says so rather than
                         printing a placeholder division. -->
                    <div>
                        <dt class="text-muted-foreground">მოცულობა</dt>
                        <dd v-if="task.planned_quantity">
                            {{ task.accepted_quantity ?? 0 }} / {{ task.planned_quantity }}<template v-if="task.unit"> {{ task.unit }}</template>
                        </dd>
                        <dd v-else>მოცულობით არ იზომება</dd>
                    </div>
                </dl>
            </section>

            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">შემსრულებლები</h2>
                <ul class="mt-3 space-y-1 text-sm">
                    <li v-for="a in task.assignees" :key="a.id">{{ a.employee_name || a.team_name }}</li>
                    <li v-if="task.assignees.length === 0" class="text-muted-foreground">დამატებითი შემსრულებელი არ არის.</li>
                </ul>
            </section>
        </div>

        <section v-if="task.checklist_items.length" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">შესამოწმებელი პუნქტები</h2>
            <div class="mt-3 space-y-2">
                <label v-for="item in task.checklist_items" :key="item.id" class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        :checked="item.is_checked"
                        :disabled="checklistForms[item.id] || !task.can.upload_attachment"
                        @change="toggleChecklist(item.id, item.is_checked)"
                    />
                    <span :class="{ 'line-through text-muted-foreground': item.is_checked }">{{ item.label }}</span>
                    <span v-if="item.is_required" class="text-destructive text-xs">*სავალდებულო</span>
                </label>
            </div>
        </section>

        <section v-if="task.dependencies.length" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">დამოკიდებულებები</h2>
            <ul class="mt-3 space-y-1 text-sm">
                <li v-for="d in task.dependencies" :key="d.id">{{ d.depends_on_title }} — {{ STATUS_LABEL[d.depends_on_status || ''] || d.depends_on_status }}</li>
            </ul>
        </section>

        <section v-if="task.can.submit" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">დასრულებაზე გაგზავნა</h2>
            <p class="text-muted-foreground mt-1 text-sm">
                მიუთითეთ <strong>ამჯერად</strong> შესრულებული მოცულობა, არა ჯამური.
                <span v-if="task.remaining_quantity">დარჩენილია {{ task.remaining_quantity }} {{ task.unit || '' }}.</span>
            </p>
            <form class="mt-3 grid gap-3 md:grid-cols-2" @submit.prevent="submitTask">
                <textarea v-model="submitForm.comment" rows="2" placeholder="კომენტარი" class="border-input bg-background rounded-md border px-3 py-2 text-sm md:col-span-2" />
                <Input v-model="submitForm.submitted_quantity" type="number" min="0" step="0.01" placeholder="შესრულებული მოცულობა" />

                <!-- TM-03: pick the evidence that goes WITH this submission.
                     Without it the request carried no attachment_ids at all,
                     so a photo-required task could never be submitted here. -->
                <div class="md:col-span-2">
                    <p class="text-sm font-medium">მტკიცებულება</p>
                    <p v-if="task.requires_photo_evidence" class="text-muted-foreground text-xs">
                        საჭიროა მინიმუმ {{ task.min_required_photos }} ფოტო. PDF/დოკუმენტი ფოტოს ვერ ჩაანაცვლებს.
                    </p>
                    <div v-if="selectableEvidence.length" class="mt-2 grid gap-1 sm:grid-cols-2">
                        <label v-for="file in selectableEvidence" :key="file.id" class="flex items-center gap-2 text-sm">
                            <input v-model="submitForm.attachment_ids" type="checkbox" :value="file.id" />
                            <span class="min-w-0 flex-1 truncate">{{ file.caption || file.original_filename }}</span>
                            <span class="text-muted-foreground text-xs">{{ file.is_photo ? 'ფოტო' : 'დოკუმენტი' }}</span>
                        </label>
                    </div>
                    <p v-else class="text-muted-foreground mt-2 text-sm">ჯერ ატვირთეთ ფაილი ქვემოთ, შემდეგ აირჩიეთ აქ.</p>
                    <p v-if="missingPhotoCount > 0" class="text-destructive mt-2 text-sm">
                        აირჩიეთ კიდევ {{ missingPhotoCount }} ფოტო.
                    </p>
                </div>

                <p v-if="(submitForm.errors as Record<string, string>).attachments" class="text-destructive text-sm md:col-span-2">{{ (submitForm.errors as Record<string, string>).attachments }}</p>
                <p v-if="(submitForm.errors as Record<string, string>).checklist" class="text-destructive text-sm md:col-span-2">{{ (submitForm.errors as Record<string, string>).checklist }}</p>
                <p v-if="(submitForm.errors as Record<string, string>).submitted_quantity" class="text-destructive text-sm md:col-span-2">{{ (submitForm.errors as Record<string, string>).submitted_quantity }}</p>
                <p v-if="(submitForm.errors as Record<string, string>).status" class="text-destructive text-sm md:col-span-2">{{ (submitForm.errors as Record<string, string>).status }}</p>
                <Button type="submit" size="sm" class="w-fit" :disabled="submitForm.processing || missingPhotoCount > 0">გაგზავნა</Button>
            </form>
        </section>

        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">ფოტოები / ფაილები</h2>
            <div v-if="task.attachments.length" class="mt-3 grid gap-2 md:grid-cols-2">
                <a
                    v-for="file in task.attachments"
                    :key="file.id"
                    :href="file.url ?? undefined"
                    target="_blank"
                    rel="noopener"
                    class="border-border hover:bg-accent flex items-center gap-3 rounded-lg border p-2 text-sm"
                    :class="!file.url && 'pointer-events-none opacity-60'"
                >
                    <img
                        v-if="file.url && file.mime_type?.startsWith('image/')"
                        :src="file.url"
                        :alt="file.caption || file.original_filename"
                        class="size-12 shrink-0 rounded object-cover"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ file.caption || file.original_filename }}</p>
                        <p class="text-muted-foreground text-xs">{{ attachmentClassificationLabel(file.classification) }} · {{ attachmentStatusLabel(file.status) }}</p>
                    </div>
                </a>
            </div>
            <p v-else class="text-muted-foreground mt-3 text-sm">ფაილი ჯერ არ არის ატვირთული.</p>
            <form v-if="task.can.upload_attachment" class="mt-4 grid gap-2 md:grid-cols-[1fr_1fr_auto]" @submit.prevent="uploadAttachment">
                <Input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required @change="selectFile" />
                <select v-model="uploadForm.classification" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option value="before">სამუშაომდე</option>
                    <option value="after">სამუშაოს შემდეგ</option>
                    <option value="other">სხვა</option>
                </select>
                <Button type="submit" :disabled="uploadForm.processing || !uploadForm.file">ატვირთვა</Button>
                <p v-if="uploadForm.errors.file" class="text-destructive text-sm md:col-span-3">{{ uploadForm.errors.file }}</p>
            </form>
        </section>

        <section v-if="task.submissions.length" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">გაგზავნები</h2>
            <div class="mt-3 space-y-3">
                <div v-for="submission in task.submissions" :key="submission.id" class="border-border rounded-lg border p-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span>{{ submission.submitted_by }} · {{ submission.submitted_at ? new Date(submission.submitted_at).toLocaleString('ka-GE') : '' }}</span>
                        <StatusBadge :label="submissionStatusLabel(submission.status)" :tone="submission.status === 'accepted' ? 'success' : submission.status === 'returned' ? 'destructive' : 'warning'" />
                    </div>
                    <p v-if="submission.comment" class="text-muted-foreground mt-1">{{ submission.comment }}</p>
                    <p v-if="submission.returned_reason" class="text-destructive mt-1">დაბრუნების მიზეზი: {{ submission.returned_reason }}</p>
                    <p v-if="submission.acceptance" class="mt-1">მიღებული მოცულობა: {{ submission.acceptance.accepted_quantity }}</p>

                    <!-- FILES-01: a reviewer must actually be able to open the
                         evidence before deciding accept/return, not just see a
                         filename — real thumbnails/links, same protected
                         endpoint as the task's own attachments above. -->
                    <div v-if="submission.photos.length" class="mt-2 grid gap-2 sm:grid-cols-2">
                        <a
                            v-for="photo in submission.photos"
                            :key="photo.id"
                            :href="photo.url ?? undefined"
                            target="_blank"
                            rel="noopener"
                            class="border-border hover:bg-accent flex items-center gap-2 rounded-lg border p-2 text-xs"
                            :class="!photo.url && 'pointer-events-none opacity-60'"
                        >
                            <img
                                v-if="photo.url && photo.mime_type?.startsWith('image/')"
                                :src="photo.url"
                                :alt="photo.caption || photo.original_filename"
                                class="size-10 shrink-0 rounded object-cover"
                            />
                            <span class="min-w-0 flex-1 truncate">{{ photo.caption || photo.original_filename }}</span>
                        </a>
                    </div>

                    <div v-if="submission.can.accept || submission.can.return" class="mt-2 flex flex-wrap gap-2">
                        <form v-if="submission.can.accept" class="flex items-center gap-2" @submit.prevent="acceptSubmission(submission)">
                            <Input v-model="acceptFormFor(submission.id).accepted_quantity" type="number" min="0" step="0.01" placeholder="მიღებული მოცულობა" class="h-8 w-40" />
                            <Button type="submit" size="sm" :disabled="acceptFormFor(submission.id).processing">მიღება</Button>
                        </form>
                        <Button v-if="submission.can.return" type="button" size="sm" variant="outline" @click="openReturn[submission.id] = !openReturn[submission.id]">დაბრუნება</Button>
                    </div>
                    <p v-else-if="submission.status === 'pending_review'" class="text-muted-foreground mt-2 text-xs">
                        ამ გაგზავნას სჭირდება დამოუკიდებელი შემმოწმებელი — სამუშაოში მონაწილე პირი ვერ დაადასტურებს მას.
                    </p>
                    <form v-if="openReturn[submission.id]" class="mt-2 flex items-center gap-2" @submit.prevent="submitReturn(submission)">
                        <Input v-model="returnFormFor(submission.id).reason" placeholder="დაბრუნების მიზეზი" required class="h-8" />
                        <Button type="submit" size="sm" variant="outline" :disabled="returnFormFor(submission.id).processing">დადასტურება</Button>
                    </form>
                </div>
            </div>
        </section>

        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">კომენტარები</h2>
            <EmptyState v-if="task.comments.length === 0" class="mt-3" title="კომენტარი ჯერ არ არის" />
            <div v-else class="mt-3 space-y-3">
                <div v-for="comment in task.comments" :key="comment.id" class="border-border rounded-lg border p-3 text-sm">
                    <p class="font-medium">{{ comment.author_name }}</p>
                    <p class="text-muted-foreground text-xs">{{ new Date(comment.created_at).toLocaleString('ka-GE') }}</p>
                    <p class="mt-1">{{ comment.body }}</p>
                    <div v-if="comment.replies.length" class="mt-2 space-y-2 border-l pl-3">
                        <div v-for="reply in comment.replies" :key="reply.id">
                            <p class="font-medium">{{ reply.author_name }}</p>
                            <p>{{ reply.body }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <form v-if="task.can.comment" class="mt-4 flex gap-2" @submit.prevent="addComment">
                <Input v-model="commentForm.body" placeholder="დაწერეთ კომენტარი..." required class="flex-1" />
                <Button type="submit" :disabled="commentForm.processing">გაგზავნა</Button>
            </form>
        </section>

        <section v-if="task.status_events.length" class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">სტატუსის ისტორია</h2>
            <ul class="mt-3 space-y-1 text-sm">
                <li v-for="event in task.status_events" :key="event.id">
                    {{ new Date(event.occurred_at).toLocaleString('ka-GE') }} — {{ STATUS_LABEL[event.from_status || ''] || event.from_status || '—' }} → {{ STATUS_LABEL[event.to_status] || event.to_status }}
                    <span v-if="event.reason" class="text-muted-foreground">({{ event.reason }})</span>
                </li>
            </ul>
        </section>
    </div>
</template>
