<script setup lang="ts">
/**
 * "ჩემი დღე" (My Day) — the employee mobile home screen (spec section 4).
 * WORKER-01: real Tasks-backed data from App\Http\Controllers\MyDayController,
 * replacing the static placeholder (docs/decisions.md DEC-050). Every action
 * here posts to the existing project-nested Tasks module routes/actions
 * (App\Http\Controllers\Tasks\TaskController) — no new mobile-only business
 * logic, per the ticket's own instruction.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Clock, PlayCircle, RotateCcw } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import BottomSheet from '@/components/mobile/BottomSheet.vue';
import CameraCapture from '@/components/mobile/CameraCapture.vue';
import TaskCard from '@/components/mobile/TaskCard.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    cacheTaskList,
    getCachedTaskList,
    OfflineQueueFullError,
    OfflineQueueQuotaExceededError,
    type CachedTaskSummary,
} from '@/lib/offlineQueue';
import { enqueuePhoto, enqueueSubmission, replayMyDayQueue } from '@/lib/taskOfflineSync';
import type { OfflineQueueItemStatus, StatusDescriptor } from '@/types';

type TaskAttachment = { id: string; caption: string | null };

type DayTask = {
    id: string;
    projectId: string;
    title: string;
    projectName?: string | null;
    dueAt?: string | null;
    status: 'assigned' | 'in_progress' | 'blocked' | 'submitted';
    requiresPhotoEvidence: boolean;
    minRequiredPhotos: number;
    attachments: TaskAttachment[];
    returnedReason?: string | null;
};

const props = withDefaults(
    defineProps<{
        hasEmployeeRecord: boolean;
        today: DayTask[];
        overdue: DayTask[];
        inReview: DayTask[];
        returned: DayTask[];
        employees: Array<{ id: string; first_name: string; last_name: string }>;
        organizationId?: string;
        userId?: string;
        offlineReviewItems?: Array<{ id: string; kind: string; taskId: string | null; reason: string | null; createdAt: string | null }>;
    }>(),
    {
        hasEmployeeRecord: true,
        today: () => [],
        overdue: () => [],
        inReview: () => [],
        returned: () => [],
        employees: () => [],
        organizationId: undefined,
        userId: undefined,
        offlineReviewItems: () => [],
    },
);

defineOptions({ layout: { mobileTitle: 'ჩემი დღე' } });

const totalActionable = computed(() => props.today.length + props.overdue.length);

const STATUS_DESCRIPTORS: Record<string, StatusDescriptor> = {
    assigned: { label: 'დასაწყები', tone: 'neutral', icon: Clock },
    in_progress: { label: 'მიმდინარეობს', tone: 'info', icon: PlayCircle },
    blocked: { label: 'დაბლოკილია', tone: 'destructive', icon: AlertTriangle },
    submitted: { label: 'განხილვაშია', tone: 'warning', icon: Clock },
};

function statusFor(task: DayTask): StatusDescriptor {
    return STATUS_DESCRIPTORS[task.status] ?? { label: task.status, tone: 'neutral' };
}

function dueLabel(task: DayTask): string | undefined {
    if (!task.dueAt) return undefined;
    const date = new Date(task.dueAt);
    return `ვადა: ${date.toLocaleDateString('ka-GE')} ${date.toLocaleTimeString('ka-GE', { hour: '2-digit', minute: '2-digit' })}`;
}

function primaryActionLabel(task: DayTask): string {
    if (task.status === 'assigned') return 'დაწყება';
    if (task.status === 'blocked') return 'განბლოკვა';
    return 'დასრულებაზე გაგზავნა';
}

// One shared sheet, opened for whichever task the worker taps. Only the id
// is kept, not a snapshot of the task object — an attachment upload/submit
// reloads this page's props with a fresh `attachments` array on a brand new
// task object, and re-deriving `sheetTask` from current props on every
// render is what lets the just-uploaded photo actually appear in
// `attachment_ids` when submitting, instead of an empty array captured at
// the moment the sheet was opened.
const sheetTaskId = ref<string | null>(null);

const sheetTask = computed<DayTask | null>(() => {
    if (sheetTaskId.value === null) return null;

    return (
        [...props.overdue, ...props.returned, ...props.today, ...props.inReview].find(
            (task) => task.id === sheetTaskId.value,
        ) ?? null
    );
});

function openSheet(task: DayTask) {
    if (task.status === 'assigned') {
        startForm.post(`/projects/${task.projectId}/tasks/${task.id}/start`, { preserveScroll: true });
        return;
    }
    if (task.status === 'blocked') {
        unblockForm.reason = '';
        sheetTaskId.value = task.id;
        return;
    }
    submitForm.comment = '';
    blockForm.reason = '';
    blockForm.blocked_owner_employee_id = '';
    showBlockSection.value = false;
    sheetTaskId.value = task.id;
}

function closeSheet() {
    sheetTaskId.value = null;
}

const startForm = useForm({});
const submitForm = useForm({ comment: '', submitted_quantity: '' as string | null });
// `submit()`'s server-side validation can reject on `attachments`/`status`
// (SubmitTaskForAcceptance's own checklist/photo-evidence/quantity rules) —
// keys the client form itself never declares as an input field.
const submitFormExtraErrors = computed(() => submitForm.errors as Record<string, string | undefined>);
const attachmentForm = useForm({ file: null as File | null });
const unblockForm = useForm({ reason: '' });
const blockForm = useForm({ reason: '', blocked_owner_employee_id: '' });
const showBlockSection = ref(false);

// PWA-01: offline draft/replay for exactly the two action kinds the backend
// schema (App\Domain\Notifications\Models\OfflineSyncSubmission) already
// anticipates — photo evidence and the final task submission. `start`/
// `block`/`unblock` stay online-only, a deliberate scope choice (that
// table's own `kind` enum is closed to `comment`/`photo`/`task_submission`
// — widening it is a schema change this ticket doesn't make).
const photoLocalStatus = ref<OfflineQueueItemStatus | null>(null);
const submitLocalStatus = ref<OfflineQueueItemStatus | null>(null);
const localSubmitError = ref<string | null>(null);

// REQ-NTF-04/05/09: honest, distinct messages for the two ways a local save
// can genuinely fail — never presented as if the item was queued
// successfully when it wasn't.
function describeQueueError(error: unknown): string {
    if (error instanceof OfflineQueueQuotaExceededError) {
        return 'ადგილი არასაკმარისია მოწყობილობაზე — წაშალეთ ძველი გაგზავნილი ჩანაწერები ან შეამცირეთ ფოტოს ხარისხი და სცადეთ ხელახლა.';
    }
    if (error instanceof OfflineQueueFullError) {
        return `ლოკალური რიგი სავსეა (მაქს. ${error.limit} ჩანაწერი) — კავშირის აღდგენამდე ვეღარ შეინახება მეტი. გაასუფთავეთ ძველი ჩანაწერები კავშირის აღდგენისთანავე.`;
    }

    return 'ლოკალურად შენახვა ვერ მოხერხდა.';
}

function isOffline(): boolean {
    return typeof navigator !== 'undefined' && navigator.onLine === false;
}

// REQ-NTF-04: bounded, read-only local snapshot of this same task list, so
// reopening the app while offline (before any real fetch can succeed) has
// something real to show instead of an empty page. Never used as a
// submission target — every action still goes through the real queue/
// replay path above. Cold-start offline access additionally depends on the
// service worker actually booting the Vue app while offline (public/sw.js's
// own navigation-caching strategy, not touched by this pass) — documented
// here rather than silently assumed solved.
const usingCachedSnapshot = ref(false);
const cachedAt = ref<string | null>(null);
const cachedTasks = ref<CachedTaskSummary[]>([]);

const BUCKET_LABELS: Record<string, string> = {
    overdue: 'ვადაგადაცილებული',
    returned: 'დაბრუნებული',
    today: 'დღეს',
    inReview: 'განხილვაში',
};

function snapshotOf(tasks: DayTask[], bucket: string): CachedTaskSummary[] {
    return tasks.map((task) => ({
        id: task.id,
        title: task.title,
        projectName: task.projectName ?? null,
        status: task.status,
        dueAt: task.dueAt ?? null,
        bucket,
    }));
}

async function refreshCachedSnapshot() {
    if (!props.organizationId || !props.userId) return;

    const snapshot = [
        ...snapshotOf(props.overdue, 'overdue'),
        ...snapshotOf(props.returned, 'returned'),
        ...snapshotOf(props.today, 'today'),
        ...snapshotOf(props.inReview, 'inReview'),
    ];

    try {
        await cacheTaskList(props.organizationId, props.userId, snapshot);
    } catch {
        // Best-effort only — a failed cache write must never block the
        // real, already-successful page render the user is currently
        // looking at.
    }
}

async function loadCachedSnapshotIfNeeded() {
    if (!props.organizationId || !props.userId) return;

    const hasRealData =
        props.today.length > 0 || props.overdue.length > 0 || props.inReview.length > 0 || props.returned.length > 0;

    if (hasRealData) {
        // A real page render already has real props — cache them for next
        // time, and never show the stale cached view over real data.
        void refreshCachedSnapshot();

        return;
    }

    if (!isOffline()) {
        // Genuinely no tasks right now, and we can actually reach the
        // server — this is a confirmed-empty state, not "no data yet".
        return;
    }

    const cached = await getCachedTaskList(props.organizationId, props.userId);
    if (cached && cached.tasks.length > 0) {
        usingCachedSnapshot.value = true;
        cachedAt.value = cached.cachedAt;
        cachedTasks.value = cached.tasks;
    }
}

async function capturePhoto(file: File) {
    const task = sheetTask.value;
    if (!task || !props.organizationId || !props.userId) return;

    if (isOffline()) {
        try {
            await enqueuePhoto(
                props.organizationId,
                props.userId,
                { taskId: task.id, projectId: task.projectId },
                file,
            );
            photoLocalStatus.value = 'queued';
            localSubmitError.value = null;
        } catch (error) {
            localSubmitError.value = describeQueueError(error);
        }

        return;
    }

    attachmentForm.file = file;
    attachmentForm
        .post(`/projects/${task.projectId}/tasks/${task.id}/attachments`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                attachmentForm.reset();
            },
            onError: async () => {
                // A request that fails to even reach the server (not a
                // validation 422) while `navigator.onLine` had not yet
                // flipped false is still a real connectivity failure —
                // queue it rather than leaving the photo silently lost.
                if (isOffline()) {
                    try {
                        await enqueuePhoto(
                            props.organizationId!,
                            props.userId!,
                            { taskId: task.id, projectId: task.projectId },
                            file,
                        );
                        photoLocalStatus.value = 'queued';
                        localSubmitError.value = null;
                    } catch (error) {
                        localSubmitError.value = describeQueueError(error);
                    }
                }
            },
        });
}

async function submitTask() {
    const task = sheetTask.value;
    if (!task || !props.organizationId || !props.userId) return;

    localSubmitError.value = null;

    if (isOffline()) {
        // Deliberate scope boundary: an offline submit is only queued when
        // the task's already-server-confirmed attachments already satisfy
        // its photo-evidence requirement. A photo captured in this SAME
        // offline session has no server attachment id yet (that only
        // exists after its own replay succeeds), and chaining "replay the
        // photo, then patch the queued submission with the id it produced,
        // then replay the submission" is real cross-item coordination this
        // pass does not build — documented here rather than silently
        // half-supported. Taking photos offline still always works (queued
        // as its own item, replayed independently); only a submit that
        // would need one of THIS session's not-yet-uploaded photos to
        // satisfy the evidence requirement is blocked with a clear message.
        if (task.requiresPhotoEvidence && task.attachments.length < task.minRequiredPhotos) {
            localSubmitError.value = 'ფოტო მტკიცებულება საჭიროა კავშირის აღდგენამდე ვერ დასრულდება — ატვირთეთ ფოტო კავშირის აღდგენისას.';

            return;
        }

        try {
            await enqueueSubmission(props.organizationId, props.userId, {
                taskId: task.id,
                projectId: task.projectId,
                comment: submitForm.comment,
                submitted_quantity: submitForm.submitted_quantity || null,
                attachment_ids: task.attachments.map((attachment) => attachment.id),
            });
            submitLocalStatus.value = 'queued';
            closeSheet();
        } catch (error) {
            localSubmitError.value = describeQueueError(error);
        }

        return;
    }

    submitForm
        .transform((data) => ({
            ...data,
            submitted_quantity: data.submitted_quantity || null,
            attachment_ids: task.attachments.map((attachment) => attachment.id),
        }))
        .post(`/projects/${task.projectId}/tasks/${task.id}/submit`, {
            preserveScroll: true,
            onSuccess: () => closeSheet(),
        });
}

const isReplaying = ref(false);

async function replayQueue() {
    if (!props.organizationId || !props.userId || isReplaying.value) return;

    isReplaying.value = true;

    try {
        const result = await replayMyDayQueue(props.organizationId, props.userId);

        if (result.sent > 0 || result.conflict > 0) {
            // Refresh this page's own props (task/attachment lists, the
            // offline-review section) so a just-replayed submission's real
            // server-side effect is visible immediately, without a full
            // reload discarding local UI state like the open sheet.
            router.reload({ only: ['today', 'overdue', 'inReview', 'returned', 'offlineReviewItems'] });
        }
    } finally {
        isReplaying.value = false;
    }
}

function handleOnline() {
    void replayQueue();
}

onMounted(() => {
    window.addEventListener('online', handleOnline);
    void loadCachedSnapshotIfNeeded();
    if (!isOffline()) {
        void replayQueue();
    }
});

onUnmounted(() => {
    window.removeEventListener('online', handleOnline);
});

const acknowledgeForms = new Map<string, ReturnType<typeof useForm>>();

function acknowledgeReviewItem(id: string) {
    if (!acknowledgeForms.has(id)) {
        acknowledgeForms.set(id, useForm({}));
    }

    acknowledgeForms.get(id)!.post(`/my-day/offline-review/${id}/acknowledge`, { preserveScroll: true });
}

function unblockTask() {
    const task = sheetTask.value;
    if (!task) return;

    unblockForm.post(`/projects/${task.projectId}/tasks/${task.id}/unblock`, {
        preserveScroll: true,
        onSuccess: () => closeSheet(),
    });
}

function reportBlocker() {
    const task = sheetTask.value;
    if (!task) return;

    blockForm.post(`/projects/${task.projectId}/tasks/${task.id}/block`, {
        preserveScroll: true,
        onSuccess: () => closeSheet(),
    });
}
</script>

<template>
    <Head title="ჩემი დღე" />

    <div class="flex flex-col gap-5 p-4">
        <div>
            <h1 class="text-foreground text-lg font-semibold">დილა მშვიდობისა 👋</h1>
            <p class="text-muted-foreground text-sm">
                <template v-if="hasEmployeeRecord">დღეს გაქვთ {{ totalActionable }} დავალება.</template>
                <template v-else>თქვენს ანგარიშს არ აქვს დაკავშირებული თანამშრომლის ჩანაწერი.</template>
            </p>
        </div>

        <div v-if="usingCachedSnapshot" class="flex flex-col gap-3">
            <p class="border-border bg-muted/50 text-muted-foreground rounded-lg border px-3 py-2 text-xs">
                ოფლაინ რეჟიმი — ნაჩვენებია ბოლოს შენახული სია
                <template v-if="cachedAt">({{ new Date(cachedAt).toLocaleString('ka-GE') }}-ის მდგომარეობით)</template>.
                ეს სია მხოლოდ სანახავია — მოქმედებები კავშირის აღდგენისას შესაძლებელი იქნება.
            </p>
            <div
                v-for="task in cachedTasks"
                :key="task.id"
                class="border-border flex items-center justify-between rounded-lg border p-3 text-sm"
            >
                <div class="flex flex-col">
                    <span class="font-medium">{{ task.title }}</span>
                    <span class="text-muted-foreground text-xs">
                        {{ task.projectName ?? 'უცნობი პროექტი' }} · {{ BUCKET_LABELS[task.bucket] ?? task.bucket }}
                    </span>
                </div>
            </div>
        </div>

        <EmptyState
            v-if="!usingCachedSnapshot && hasEmployeeRecord && totalActionable === 0 && inReview.length === 0 && returned.length === 0"
            title="დღეს დავალება არ გაქვთ"
            description="ახალი დავალების მინიჭებისას აქ გამოჩნდება."
        />

        <div v-if="offlineReviewItems.length" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">საჭიროებს შემოწმებას</h2>
            <div
                v-for="item in offlineReviewItems"
                :key="item.id"
                class="border-border bg-amber-500/10 flex flex-col gap-2 rounded-lg border p-3 text-sm"
            >
                <p>ოფლაინში გაგზავნილი {{ item.kind === 'photo' ? 'ფოტო' : 'დასრულების მოთხოვნა' }} ავტომატურად ვერ დამუშავდა.</p>
                <p v-if="item.reason" class="text-muted-foreground text-xs">{{ item.reason }}</p>
                <Button variant="outline" size="sm" class="w-fit" @click="acknowledgeReviewItem(item.id)">გასაგებია</Button>
            </div>
        </div>

        <div v-if="overdue.length" class="flex flex-col gap-3">
            <h2 class="text-destructive text-sm font-semibold">ვადაგადაცილებული</h2>
            <TaskCard
                v-for="task in overdue"
                :key="task.id"
                :title="task.title"
                :project-name="task.projectName ?? undefined"
                :due-label="dueLabel(task)"
                :status="statusFor(task)"
                :primary-action-label="primaryActionLabel(task)"
                @open="openSheet(task)"
                @primary-action="openSheet(task)"
            />
        </div>

        <div v-if="returned.length" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">დაბრუნებული</h2>
            <div v-for="task in returned" :key="task.id" class="flex flex-col gap-2">
                <p class="text-muted-foreground rounded-lg bg-amber-500/10 p-2 text-xs">
                    <RotateCcw class="mr-1 inline size-3.5" aria-hidden="true" />
                    მენეჯერის შენიშვნა: {{ task.returnedReason }}
                </p>
                <TaskCard
                    :title="task.title"
                    :project-name="task.projectName ?? undefined"
                    :due-label="dueLabel(task)"
                    :status="statusFor(task)"
                    primary-action-label="დასრულებაზე ხელახლა გაგზავნა"
                    @open="openSheet(task)"
                    @primary-action="openSheet(task)"
                />
            </div>
        </div>

        <div v-if="today.length" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">დღეს</h2>
            <TaskCard
                v-for="task in today"
                :key="task.id"
                :title="task.title"
                :project-name="task.projectName ?? undefined"
                :due-label="dueLabel(task)"
                :status="statusFor(task)"
                :primary-action-label="primaryActionLabel(task)"
                @open="openSheet(task)"
                @primary-action="openSheet(task)"
            />
        </div>

        <div v-if="inReview.length" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">განხილვაში</h2>
            <TaskCard
                v-for="task in inReview"
                :key="task.id"
                :title="task.title"
                :project-name="task.projectName ?? undefined"
                :status="statusFor(task)"
                @open="() => {}"
            />
        </div>

        <BottomSheet
            :open="sheetTaskId !== null"
            :title="sheetTask?.title ?? ''"
            description="დაამატეთ კომენტარი ან ფოტო დასრულებაზე გაგზავნამდე."
            @update:open="(v) => !v && closeSheet()"
        >
            <div v-if="sheetTask?.status === 'blocked'" class="flex flex-col gap-3 pb-4">
                <Textarea v-model="unblockForm.reason" rows="3" placeholder="განბლოკვის მიზეზი" required />
                <p v-if="unblockForm.errors.reason" class="text-destructive text-sm">{{ unblockForm.errors.reason }}</p>
                <Button class="h-11 w-full text-base" :disabled="unblockForm.processing" @click="unblockTask">დადასტურება</Button>
            </div>

            <div v-else class="flex flex-col gap-3 pb-4">
                <p v-if="sheetTask?.requiresPhotoEvidence" class="text-muted-foreground text-xs">
                    საჭიროა მინიმუმ {{ sheetTask?.minRequiredPhotos }} ფოტო მტკიცებულებად.
                </p>

                <div v-if="sheetTask?.attachments.length" class="text-muted-foreground text-xs">
                    ატვირთულია {{ sheetTask?.attachments.length }} ფოტო/ფაილი.
                </div>

                <Textarea v-model="submitForm.comment" rows="3" placeholder="კომენტარი (არასავალდებულო)" />
                <p v-if="submitForm.errors.comment" class="text-destructive text-sm">{{ submitForm.errors.comment }}</p>

                <CameraCapture
                    :status="photoLocalStatus ?? (attachmentForm.processing ? 'sending' : attachmentForm.wasSuccessful ? 'sent' : null)"
                    @capture="capturePhoto"
                />
                <p v-if="attachmentForm.errors.file" class="text-destructive text-sm">{{ attachmentForm.errors.file }}</p>

                <Button class="h-11 w-full text-base" :disabled="submitForm.processing || submitLocalStatus === 'queued'" @click="submitTask">
                    {{ submitLocalStatus === 'queued' ? 'ლოკალურად შენახულია — გაიგზავნება კავშირის აღდგენისას' : 'დასრულებაზე გაგზავნა' }}
                </Button>
                <p v-if="localSubmitError" class="text-destructive text-sm">{{ localSubmitError }}</p>
                <p v-if="submitFormExtraErrors.attachments" class="text-destructive text-sm">{{ submitFormExtraErrors.attachments }}</p>
                <p v-if="submitFormExtraErrors.status" class="text-destructive text-sm">{{ submitFormExtraErrors.status }}</p>

                <button
                    type="button"
                    class="text-muted-foreground mt-2 text-left text-sm underline"
                    @click="showBlockSection = !showBlockSection"
                >
                    დაბრკოლების დაფიქსირება
                </button>

                <div v-if="showBlockSection" class="border-border flex flex-col gap-2 rounded-lg border p-3">
                    <Textarea v-model="blockForm.reason" rows="2" placeholder="დაბრკოლების მიზეზი" required />
                    <select
                        v-model="blockForm.blocked_owner_employee_id"
                        required
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="" disabled>ვინ არის პასუხისმგებელი მოხსნაზე</option>
                        <option v-for="employee in employees" :key="employee.id" :value="employee.id">
                            {{ employee.first_name }} {{ employee.last_name }}
                        </option>
                    </select>
                    <Button variant="destructive" class="h-11 w-full text-base" :disabled="blockForm.processing" @click="reportBlocker">
                        დაბლოკვის დადასტურება
                    </Button>
                </div>
            </div>
        </BottomSheet>
    </div>
</template>
