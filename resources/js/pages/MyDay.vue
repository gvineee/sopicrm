<script setup lang="ts">
/**
 * "ჩემი დღე" (My Day) — the employee mobile home screen (spec section 4).
 * This is the first reserved mobile bottom-nav destination (see
 * lib/mobileNav.ts) and its own composition, not a shrunk desktop
 * dashboard: a single-column, thumb-reachable list of today's work with
 * one-handed primary actions (start work, comment, take a photo, submit).
 *
 * Data below is static placeholder content (see docs/decisions.md
 * DEC-050) — the Tasks/Attendance modules replace it with real,
 * server-paginated data without needing to change this composition.
 */
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, Clock, PlayCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import BottomSheet from '@/components/mobile/BottomSheet.vue';
import CameraCapture from '@/components/mobile/CameraCapture.vue';
import TaskCard from '@/components/mobile/TaskCard.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type { OfflineQueueItemStatus, StatusDescriptor } from '@/types';

defineOptions({ layout: { mobileTitle: 'ჩემი დღე' } });

type DemoTask = {
    id: string;
    title: string;
    projectName: string;
    dueLabel: string;
    status: StatusDescriptor;
};

const demoTasks: DemoTask[] = [
    {
        id: 'd1',
        title: 'არმატურის მონტაჟი — 4 სართული',
        projectName: 'მთაწმინდა 12',
        dueLabel: 'დღეს, 14:00-მდე',
        status: { label: 'დაწყებული', tone: 'info', icon: PlayCircle },
    },
    {
        id: 'd2',
        title: 'უსაფრთხოების შემოწმების ფურცელი',
        projectName: 'მთაწმინდა 12',
        dueLabel: 'დღეს, 17:00-მდე',
        status: { label: 'დასაწყები', tone: 'neutral', icon: Clock },
    },
    {
        id: 'd3',
        title: 'დღიური ანგარიშის გაგზავნა',
        projectName: 'მთაწმინდა 12',
        dueLabel: 'გუშინ დასრულდა',
        status: { label: 'დასრულებული', tone: 'success', icon: CheckCircle2 },
    },
];

const sheetOpenFor = ref<string | null>(null);
const comment = ref('');
const photoStatus = ref<OfflineQueueItemStatus | null>(null);

const activeTask = computed(
    () => demoTasks.find((t) => t.id === sheetOpenFor.value) ?? null,
);

function openTask(id: string) {
    sheetOpenFor.value = id;
}

function onCapture() {
    // Demo-only: a real Tasks-module wire-up enqueues this via
    // lib/offlineQueue.ts (enqueue → 'queued' → replay on reconnect).
    photoStatus.value = 'queued';
}

function submitCompletion() {
    photoStatus.value = 'sent';
    sheetOpenFor.value = null;
    comment.value = '';
}
</script>

<template>
    <Head title="ჩემი დღე" />

    <div class="flex flex-col gap-4 p-4">
        <div>
            <h1 class="text-foreground text-lg font-semibold">
                დილა მშვიდობისა 👋
            </h1>
            <p class="text-muted-foreground text-sm">
                დღეს გაქვთ {{ demoTasks.length }} დავალება.
            </p>
        </div>

        <Button class="h-11 w-full text-base">სამუშაოს დაწყება</Button>

        <div class="flex flex-col gap-3">
            <TaskCard
                v-for="task in demoTasks"
                :key="task.id"
                :title="task.title"
                :project-name="task.projectName"
                :due-label="task.dueLabel"
                :status="task.status"
                primary-action-label="დასრულებაზე გაგზავნა"
                @open="openTask(task.id)"
                @primary-action="openTask(task.id)"
            />
        </div>

        <BottomSheet
            :open="sheetOpenFor !== null"
            :title="activeTask?.title ?? ''"
            description="დაამატეთ კომენტარი ან ფოტო დასრულებაზე გაგზავნამდე."
            @update:open="(v) => !v && (sheetOpenFor = null)"
        >
            <div class="flex flex-col gap-3 pb-4">
                <Textarea
                    v-model="comment"
                    rows="3"
                    placeholder="კომენტარი (არასავალდებულო)"
                />
                <CameraCapture
                    :status="photoStatus"
                    @capture="onCapture"
                    @clear="photoStatus = null"
                />
                <Button class="h-11 w-full text-base" @click="submitCompletion"
                    >დასრულებაზე გაგზავნა</Button
                >
            </div>
        </BottomSheet>
    </div>
</template>
