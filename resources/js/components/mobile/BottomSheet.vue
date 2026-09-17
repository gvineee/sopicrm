<script setup lang="ts">
/**
 * Mobile bottom sheet (spec 4/17: "bottom sheets"). Built on the same Sheet
 * primitive as the desktop DetailDrawer (side="bottom" here), with a
 * safe-area-aware bottom padding and a max-height that leaves room for the
 * on-screen keyboard when a text field inside is focused (visualViewport,
 * feature-detected, falls back to a fixed 85vh cap when unavailable).
 */
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetDescription,
} from '@/components/ui/sheet';
import { onMounted, onUnmounted, ref } from 'vue';

withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
    }>(),
    {},
);

defineEmits<{ 'update:open': [boolean] }>();

// Track the visual viewport so the sheet shrinks above an open on-screen
// keyboard instead of being covered by it (feature-detected; iOS/Android
// both expose visualViewport in current versions, but this degrades to a
// static max-height if it's absent rather than throwing).
const maxHeightPx = ref<number | null>(null);

function updateMaxHeight() {
    if (typeof window === 'undefined' || !window.visualViewport) {
        maxHeightPx.value = null;
        return;
    }
    maxHeightPx.value = Math.round(window.visualViewport.height * 0.9);
}

onMounted(() => {
    updateMaxHeight();
    window.visualViewport?.addEventListener('resize', updateMaxHeight);
});
onUnmounted(() => {
    window.visualViewport?.removeEventListener('resize', updateMaxHeight);
});
</script>

<template>
    <Sheet :open="open" @update:open="(v) => $emit('update:open', v)">
        <SheetContent
            side="bottom"
            class="pb-safe max-h-[85vh] overflow-y-auto rounded-t-2xl sm:max-w-full"
            :style="maxHeightPx ? { maxHeight: `${maxHeightPx}px` } : undefined"
        >
            <div
                class="bg-border mx-auto mb-1 h-1.5 w-10 shrink-0 rounded-full"
                aria-hidden="true"
            />
            <SheetHeader class="text-left">
                <SheetTitle>{{ title }}</SheetTitle>
                <SheetDescription v-if="description">{{
                    description
                }}</SheetDescription>
            </SheetHeader>
            <div class="px-1">
                <slot />
            </div>
        </SheetContent>
    </Sheet>
</template>
