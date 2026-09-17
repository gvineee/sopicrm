<script setup lang="ts">
/**
 * Standard "nothing here yet" state — spec section 4 requires every screen
 * to define loading/empty/error/permission-denied/offline explicitly rather
 * than leaving a blank table.
 */
import { Inbox } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: LucideIcon;
        actionLabel?: string;
    }>(),
    {
        icon: () => Inbox,
    },
);

defineEmits<{ action: [] }>();
</script>

<template>
    <div
        class="border-border flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-12 text-center"
        role="status"
    >
        <div
            class="bg-muted text-muted-foreground flex size-12 items-center justify-center rounded-full"
        >
            <component :is="icon" class="size-6" aria-hidden="true" />
        </div>
        <div class="space-y-1">
            <p class="text-foreground font-medium">{{ title }}</p>
            <p
                v-if="description"
                class="text-muted-foreground max-w-sm text-sm"
            >
                {{ description }}
            </p>
        </div>
        <Button
            v-if="actionLabel"
            variant="outline"
            size="sm"
            @click="$emit('action')"
        >
            {{ actionLabel }}
        </Button>
    </div>
</template>
