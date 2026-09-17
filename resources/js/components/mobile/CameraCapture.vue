<script setup lang="ts">
/**
 * Camera-capture action (spec 4: "camera action"; spec 17: photo drafts
 * show "ლოკალურად შენახულია" vs "სერვერზე გაგზავნილია" as distinct states).
 *
 * Uses a plain `<input type="file" accept="image/*" capture="environment">`
 * — the standards-based way to reach the device camera from a web app, with
 * an automatic, native fallback to the file picker on devices/browsers
 * without camera capture support. No native-app camera plugin is used or
 * assumed (this stays a PWA, not a wrapped native app per spec 17's own
 * framing).
 */
import { Camera, Check, Cloud, RotateCw, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { OfflineQueueItemStatus } from '@/types';

const props = withDefaults(
    defineProps<{
        /** Local preview status once a photo is attached, if the consumer is tracking one. */
        status?: OfflineQueueItemStatus | null;
    }>(),
    { status: null },
);

const emit = defineEmits<{ capture: [File]; clear: [] }>();

const inputRef = ref<HTMLInputElement | null>(null);
const previewUrl = ref<string | null>(null);

function openCamera() {
    inputRef.value?.click();
}

function onFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = URL.createObjectURL(file);
    emit('capture', file);
}

function clear() {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
    if (inputRef.value) inputRef.value.value = '';
    emit('clear');
}

const statusLabel = computed(() => {
    switch (props.status) {
        case 'draft':
        case 'queued':
            return {
                text: 'ლოკალურად შენახულია',
                icon: Check,
                tone: 'text-muted-foreground',
            };
        case 'sending':
            return {
                text: 'იგზავნება…',
                icon: RotateCw,
                tone: 'text-info-soft-foreground',
            };
        case 'sent':
            return {
                text: 'სერვერზე გაგზავნილია',
                icon: Cloud,
                tone: 'text-success-soft-foreground',
            };
        case 'failed':
        case 'conflict':
            return {
                text: 'გაგზავნა ვერ მოხერხდა — სცადეთ თავიდან',
                icon: X,
                tone: 'text-destructive-soft-foreground',
            };
        default:
            return null;
    }
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <input
            ref="inputRef"
            type="file"
            accept="image/*"
            capture="environment"
            class="sr-only"
            @change="onFileChange"
        />

        <div v-if="previewUrl" class="relative w-fit">
            <img
                :src="previewUrl"
                alt="გადაღებული ფოტოს გადახედვა"
                class="h-32 w-32 rounded-lg object-cover"
            />
            <button
                type="button"
                aria-label="ფოტოს წაშლა"
                class="border-border bg-background absolute -top-2 -right-2 flex size-7 items-center justify-center rounded-full border shadow-sm"
                @click="clear"
            >
                <X class="size-3.5" />
            </button>
        </div>

        <Button
            v-else
            class="h-11 w-full text-base"
            variant="outline"
            @click="openCamera"
        >
            <Camera class="size-5" aria-hidden="true" />
            ფოტოს გადაღება
        </Button>

        <p
            v-if="statusLabel"
            :class="['flex items-center gap-1 text-xs', statusLabel.tone]"
        >
            <component
                :is="statusLabel.icon"
                class="size-3.5"
                aria-hidden="true"
            />
            {{ statusLabel.text }}
        </p>
    </div>
</template>
