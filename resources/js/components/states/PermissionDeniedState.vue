<script setup lang="ts">
/**
 * Shown when the server denies a Policy/Gate check (403). This is a UX
 * affordance ONLY — the hard constraint is that the check itself already
 * happened server-side; this component never decides access, it just
 * explains a denial that already occurred (spec: "მხოლოდ მენიუს დამალვა
 * არ არის დაცვა").
 */
import { ShieldAlert } from '@lucide/vue';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        backHref?: string;
        backLabel?: string;
    }>(),
    {
        title: 'წვდომა შეზღუდულია',
        description:
            'თქვენ არ გაქვთ ამ გვერდის ნახვის უფლება. თუ ეს შეცდომაა, დაუკავშირდით ადმინისტრატორს.',
        backLabel: 'მთავარზე დაბრუნება',
    },
);
</script>

<template>
    <div
        class="border-warning-soft bg-warning-soft/40 flex flex-col items-center gap-3 rounded-xl border px-6 py-12 text-center"
        role="alert"
    >
        <div
            class="bg-warning-soft text-warning-soft-foreground flex size-12 items-center justify-center rounded-full"
        >
            <ShieldAlert class="size-6" aria-hidden="true" />
        </div>
        <div class="space-y-1">
            <p class="text-foreground font-medium">{{ title }}</p>
            <p class="text-muted-foreground max-w-sm text-sm">
                {{ description }}
            </p>
        </div>
        <Button v-if="backHref" as-child variant="outline" size="sm">
            <a :href="backHref">{{ backLabel }}</a>
        </Button>
    </div>
</template>
