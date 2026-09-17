<script setup lang="ts">
/**
 * Mobile task card for "ჩემი დღე" / "ჩემი დავალებები" (spec 4: "task
 * cards"). Status is always rendered as visible text via StatusBadge, never
 * a color-only dot (spec 4: "სტატუსები ტექსტითაც და არა მხოლოდ ფერით").
 * Primary action button is full-width and >=44px tall for one-handed mobile
 * use (spec 4: "მთავარი მოქმედებები იყოს ერთი ხელით გამოსაყენებელი").
 */
import { MapPin } from '@lucide/vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import type { StatusDescriptor } from '@/types';

defineProps<{
    title: string;
    projectName?: string;
    dueLabel?: string;
    status: StatusDescriptor;
    primaryActionLabel?: string;
}>();

defineEmits<{ primaryAction: []; open: [] }>();
</script>

<template>
    <div
        class="border-border bg-card flex flex-col gap-2 rounded-xl border p-4"
        role="button"
        tabindex="0"
        @click="$emit('open')"
        @keyup.enter="$emit('open')"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <h3
                class="text-foreground min-w-0 flex-1 text-base font-medium break-words"
            >
                {{ title }}
            </h3>
            <StatusBadge
                class="shrink-0"
                :label="status.label"
                :tone="status.tone"
                :icon="status.icon"
            />
        </div>

        <div
            v-if="projectName || dueLabel"
            class="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
        >
            <span v-if="projectName" class="inline-flex items-center gap-1">
                <MapPin class="size-3.5" aria-hidden="true" />
                {{ projectName }}
            </span>
            <span v-if="dueLabel">{{ dueLabel }}</span>
        </div>

        <Button
            v-if="primaryActionLabel"
            class="mt-1 h-11 w-full text-base"
            @click.stop="$emit('primaryAction')"
        >
            {{ primaryActionLabel }}
        </Button>
    </div>
</template>
