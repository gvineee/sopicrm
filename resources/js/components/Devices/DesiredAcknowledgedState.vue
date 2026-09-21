<script setup lang="ts">
/**
 * spec section 6 hard UI requirement: "UI-ში აჩვენე desired state და
 * თითოეულ მოწყობილობაზე acknowledged state ... Offline მოწყობილობაზე
 * გაუქმების დაჭერა არ გამოჩნდეს როგორც დასრულებული გაუქმება." Renders
 * exactly what App\Domain\Devices\Services\DeviceDesiredStateResolver
 * computed — this component never infers completion on its own; if
 * `isPending` is true the acknowledged badge is always shown as pending,
 * regardless of what `desired` says.
 */
import StatusBadge from '@/components/StatusBadge.vue';
import type { StatusTone } from '@/types';

const props = defineProps<{
    deviceSerial: string;
    desired: string;
    acknowledged: string;
    isPending: boolean;
}>();

const EFFECT_LABEL: Record<string, string> = {
    granted: 'დაშვებულია',
    revoked: 'გაუქმებულია',
    not_synced: 'სინქრონიზებული არაა',
    unknown: 'უცნობია',
};

function toneFor(effect: string, pending: boolean): StatusTone {
    if (pending) return 'warning';
    if (effect === 'granted') return 'success';
    if (effect === 'revoked') return 'destructive';
    return 'neutral';
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-muted-foreground min-w-0 truncate">{{ deviceSerial }}</span>
        <span class="text-muted-foreground">სასურველი:</span>
        <StatusBadge :label="EFFECT_LABEL[desired] ?? desired" :tone="toneFor(desired, false)" />
        <span class="text-muted-foreground">დადასტურებული:</span>
        <StatusBadge
            :label="isPending ? 'მოლოდინშია' : (EFFECT_LABEL[acknowledged] ?? acknowledged)"
            :tone="toneFor(acknowledged, isPending)"
        />
    </div>
</template>
