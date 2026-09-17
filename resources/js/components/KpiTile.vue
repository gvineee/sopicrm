<script setup lang="ts">
/**
 * Desktop dashboard KPI tile (spec 4: "სასარგებლო KPI-ები... ყოველი
 * მაჩვენებელი რეალურ მონაცემს და drill-down-ს უკავშირდება"). `href` makes
 * the whole tile a drill-down link — a KPI without a destination is a
 * decorative graphic, which spec 4 explicitly forbids ("არ გამოიყენო
 * შემთხვევითი დეკორატიული გრაფიკები").
 */
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { TrendingDown, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    label: string;
    value: string;
    href?: string;
    icon?: LucideIcon;
    /** Signed change vs. the previous period, e.g. "+12%" or "-4". Optional. */
    delta?: string;
    deltaTone?: 'positive' | 'negative' | 'neutral';
    loading?: boolean;
}>();

const deltaIcon = computed(() => {
    if (props.deltaTone === 'positive') return TrendingUp;
    if (props.deltaTone === 'negative') return TrendingDown;
    return null;
});

const deltaClass = computed(() => {
    if (props.deltaTone === 'positive')
        return 'text-success-soft-foreground bg-success-soft';
    if (props.deltaTone === 'negative')
        return 'text-destructive-soft-foreground bg-destructive-soft';
    return 'text-muted-foreground bg-muted';
});
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        :class="
            cn(
                'border-border bg-card flex flex-col gap-2 rounded-xl border p-4 transition-colors',
                href && 'hover:border-primary/40 hover:bg-accent/40',
            )
        "
    >
        <div class="flex items-center justify-between gap-2">
            <span
                class="text-muted-foreground min-w-0 flex-1 truncate text-sm"
                >{{ label }}</span
            >
            <component
                :is="icon"
                v-if="icon"
                class="text-muted-foreground size-4 shrink-0"
                aria-hidden="true"
            />
        </div>

        <div v-if="loading" class="bg-muted h-8 w-2/3 animate-pulse rounded" />
        <div v-else class="text-foreground text-2xl font-semibold">
            {{ value }}
        </div>

        <span
            v-if="delta"
            :class="
                cn(
                    'inline-flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                    deltaClass,
                )
            "
        >
            <component
                :is="deltaIcon"
                v-if="deltaIcon"
                class="size-3"
                aria-hidden="true"
            />
            {{ delta }}
        </span>
    </component>
</template>
