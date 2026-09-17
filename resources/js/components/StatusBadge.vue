<script setup lang="ts">
/**
 * The one place status color mapping lives. Every screen showing a status
 * (task, timesheet exception, device health, tool assignment, …) should
 * render it through this component instead of hand-rolling a colored badge,
 * so "color is never the only signal" (spec section 4) is enforced by
 * construction: the label text and, optionally, an icon are always
 * rendered alongside the tone.
 */
import type { LucideIcon } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { StatusTone } from '@/types';

const props = defineProps<{
    label: string;
    tone: StatusTone;
    icon?: LucideIcon;
}>();

const badgeVariant = {
    neutral: 'secondary',
    success: 'success',
    warning: 'warning',
    destructive: 'destructiveSoft',
    info: 'info',
} as const satisfies Record<StatusTone, string>;

const variant = badgeVariant[props.tone];
</script>

<template>
    <Badge :variant="variant">
        <component :is="icon" v-if="icon" class="size-3" aria-hidden="true" />
        {{ label }}
    </Badge>
</template>
