<script setup lang="ts">
/**
 * Standard error state: always paired with a concrete recovery action —
 * spec 4 "შეცდომა მომხმარებელს სთავაზობს აღდგენის მოქმედებას."
 */
import { AlertTriangle } from '@lucide/vue';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        retryLabel?: string;
    }>(),
    {
        title: 'რაღაც შეცდომა მოხდა',
        retryLabel: 'ხელახლა ცდა',
    },
);

defineEmits<{ retry: [] }>();
</script>

<template>
    <div
        class="border-destructive-soft bg-destructive-soft/40 flex flex-col items-center gap-3 rounded-xl border px-6 py-12 text-center"
        role="alert"
    >
        <div
            class="bg-destructive-soft text-destructive-soft-foreground flex size-12 items-center justify-center rounded-full"
        >
            <AlertTriangle class="size-6" aria-hidden="true" />
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
        <Button variant="outline" size="sm" @click="$emit('retry')">
            {{ retryLabel }}
        </Button>
    </div>
</template>
