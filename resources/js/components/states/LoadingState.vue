<script setup lang="ts">
/**
 * Loading skeleton shown ONLY while data is in flight (spec 4: "Loading
 * skeleton მხოლოდ დატვირთვისას") — never left on screen once data (or an
 * error/empty state) is available. `variant` picks a shape matching the
 * content that will replace it, so the layout doesn't jump.
 */
import { Skeleton } from '@/components/ui/skeleton';

withDefaults(
    defineProps<{
        variant?: 'rows' | 'cards' | 'kpi' | 'detail';
        rows?: number;
    }>(),
    {
        variant: 'rows',
        rows: 5,
    },
);
</script>

<template>
    <div role="status" aria-live="polite" aria-label="იტვირთება">
        <div v-if="variant === 'rows'" class="space-y-2">
            <div v-for="i in rows" :key="i" class="flex items-center gap-3">
                <Skeleton class="h-4 w-1/4" />
                <Skeleton class="h-4 w-1/3" />
                <Skeleton class="h-4 flex-1" />
                <Skeleton class="h-4 w-16" />
            </div>
        </div>

        <div v-else-if="variant === 'cards'" class="grid gap-3 sm:grid-cols-2">
            <div
                v-for="i in rows"
                :key="i"
                class="border-border space-y-3 rounded-xl border p-4"
            >
                <Skeleton class="h-4 w-2/3" />
                <Skeleton class="h-3 w-1/2" />
                <Skeleton class="h-3 w-full" />
            </div>
        </div>

        <div
            v-else-if="variant === 'kpi'"
            class="grid grid-cols-2 gap-3 sm:grid-cols-4"
        >
            <div
                v-for="i in 4"
                :key="i"
                class="border-border space-y-2 rounded-xl border p-4"
            >
                <Skeleton class="h-3 w-1/2" />
                <Skeleton class="h-6 w-2/3" />
            </div>
        </div>

        <div v-else class="space-y-3">
            <Skeleton class="h-6 w-1/3" />
            <Skeleton class="h-4 w-full" />
            <Skeleton class="h-4 w-5/6" />
            <Skeleton class="h-4 w-2/3" />
        </div>

        <span class="sr-only">იტვირთება…</span>
    </div>
</template>
