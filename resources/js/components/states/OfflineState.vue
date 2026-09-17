<script setup lang="ts">
/**
 * Shown when a screen needs live data but the device has no connection.
 * Pair with lib/offlineQueue.ts for screens that let the user keep working
 * locally instead of just blocking — this component is the "blocking"
 * variant for data that genuinely cannot be fetched offline.
 */
import { WifiOff } from '@lucide/vue';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        retryLabel?: string;
    }>(),
    {
        title: 'ინტერნეტ კავშირი არ არის',
        description:
            'ეს მონაცემები საჭიროებს კავშირს სერვერთან. კავშირის აღდგენისას გვერდი ავტომატურად განახლდება.',
        retryLabel: 'ხელახლა შემოწმება',
    },
);

defineEmits<{ retry: [] }>();
</script>

<template>
    <div
        class="border-border flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-12 text-center"
        role="status"
    >
        <div
            class="bg-muted text-muted-foreground flex size-12 items-center justify-center rounded-full"
        >
            <WifiOff class="size-6" aria-hidden="true" />
        </div>
        <div class="space-y-1">
            <p class="text-foreground font-medium">{{ title }}</p>
            <p class="text-muted-foreground max-w-sm text-sm">
                {{ description }}
            </p>
        </div>
        <Button variant="outline" size="sm" @click="$emit('retry')">
            {{ retryLabel }}
        </Button>
    </div>
</template>
