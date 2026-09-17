<script setup lang="ts">
/**
 * "Someone else edited this" recovery UI — spec 4 "მრავალმომხმარებლიან
 * რედაქტირებაზე version conflict და გასაგები აღდგენის გზა" and the
 * optimistic-concurrency `version` column convention in docs/architecture.md
 * §5. A 409 response carrying the current server version/record is the
 * trigger; this component never guesses which side is "right" — it always
 * shows both and makes the user choose.
 */
import { GitMerge } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    /** Human label for what changed, e.g. "დავალება" or "ტაბელი". */
    entityLabel: string;
    /** Who changed it server-side, if known. */
    changedBy?: string;
    changedAt?: string;
    reloadLabel?: string;
    discardLabel?: string;
}>();

defineEmits<{
    /** Reload the current server version, discarding the local edit. */
    reload: [];
    /** Keep editing locally and let the user manually re-apply their change. */
    keepEditing: [];
}>();
</script>

<template>
    <div
        class="border-warning-soft bg-warning-soft/40 flex flex-col gap-3 rounded-xl border p-4"
        role="alert"
    >
        <div class="flex items-start gap-3">
            <div
                class="bg-warning-soft text-warning-soft-foreground flex size-9 shrink-0 items-center justify-center rounded-full"
            >
                <GitMerge class="size-5" aria-hidden="true" />
            </div>
            <div class="space-y-1">
                <p class="text-foreground font-medium">
                    {{ entityLabel }} შეიცვალა სხვის მიერ
                </p>
                <p class="text-muted-foreground text-sm">
                    <template v-if="changedBy">
                        ცვლილება შეიტანა {{ changedBy
                        }}<template v-if="changedAt">, {{ changedAt }}</template
                        >.
                    </template>
                    თქვენი ცვლილება ჯერ არ არის შენახული. აირჩიეთ: ჩატვირთეთ
                    უახლესი ვერსია (თქვენი ცვლილება დაიკარგება), ან განაგრძეთ
                    რედაქტირება და ხელახლა გადაიტანეთ საჭირო ცვლილება.
                </p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 pl-12">
            <Button variant="warning" size="sm" @click="$emit('reload')">
                {{ reloadLabel ?? 'უახლესი ვერსიის ჩატვირთვა' }}
            </Button>
            <Button variant="outline" size="sm" @click="$emit('keepEditing')">
                {{ discardLabel ?? 'რედაქტირების გაგრძელება' }}
            </Button>
        </div>
    </div>
</template>
