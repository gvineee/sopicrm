import type { LucideIcon } from '@lucide/vue';

/**
 * Status "tone" drives color, but every consumer (StatusBadge, TaskCard, …)
 * must render `label` as visible text and `icon` as a shape too — hard
 * constraint: color is never the only signal (spec section 4 "ფერი არ იყოს
 * ინფორმაციის ერთადერთი გადამცემი").
 */
export type StatusTone =
    | 'neutral'
    | 'success'
    | 'warning'
    | 'destructive'
    | 'info';

export type StatusDescriptor = {
    label: string;
    tone: StatusTone;
    icon?: LucideIcon;
};
