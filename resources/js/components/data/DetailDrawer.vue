<script setup lang="ts">
/**
 * Desktop detail drawer primitive (spec 4: "detail drawer"). Warns before
 * closing when the consumer marks it `dirty` — spec 4 "ცვლილების
 * დაკარგვისას გაფრთხილება."
 */
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetDescription,
} from '@/components/ui/sheet';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        dirty?: boolean;
        widthClass?: string;
    }>(),
    {
        dirty: false,
        widthClass: 'sm:max-w-xl',
    },
);

const emit = defineEmits<{ 'update:open': [boolean]; close: [] }>();

const confirmingClose = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (value: boolean) => {
        if (!value && props.dirty) {
            confirmingClose.value = true;
            return; // veto the close, ask first
        }
        emit('update:open', value);
        if (!value) emit('close');
    },
});

function discardAndClose() {
    confirmingClose.value = false;
    emit('update:open', false);
    emit('close');
}
</script>

<template>
    <Sheet v-model:open="isOpen">
        <SheetContent :class="widthClass" class="overflow-y-auto">
            <SheetHeader>
                <SheetTitle>{{ title }}</SheetTitle>
                <SheetDescription v-if="description">{{
                    description
                }}</SheetDescription>
            </SheetHeader>

            <div
                v-if="confirmingClose"
                class="border-warning-soft bg-warning-soft/50 mx-4 rounded-lg border p-3 text-sm"
            >
                <p class="text-warning-soft-foreground mb-2 font-medium">
                    შენახული არ არის ცვლილება
                </p>
                <p class="text-muted-foreground mb-3">
                    თუ დახურავთ, ცვლილება დაიკარგება. გსურთ გაგრძელება?
                </p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="border-border hover:bg-accent rounded-md border px-3 py-1.5 text-sm"
                        @click="confirmingClose = false"
                    >
                        რედაქტირების გაგრძელება
                    </button>
                    <button
                        type="button"
                        class="bg-destructive text-destructive-foreground hover:bg-destructive/90 rounded-md px-3 py-1.5 text-sm"
                        @click="discardAndClose"
                    >
                        ცვლილების გაუქმება
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-4 pb-4">
                <slot />
            </div>

            <div v-if="$slots.footer" class="border-border border-t px-4 py-3">
                <slot name="footer" />
            </div>
        </SheetContent>
    </Sheet>
</template>
