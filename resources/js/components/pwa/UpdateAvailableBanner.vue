<script setup lang="ts">
/**
 * Safe service-worker update banner (spec 17: "შესთავაზოს განახლება
 * draft-ის შენახვის შემდეგ"). Never auto-reloads; the user must click
 * "განახლება" — see lib/pwa.ts for why this can never destroy an unsent
 * draft (drafts live in IndexedDB, untouched by SW activation).
 */
import { RefreshCw, X } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { applyUpdate, updateAvailable } from '@/lib/pwa';

const dismissed = ref(false);
</script>

<template>
    <div
        v-if="updateAvailable && !dismissed"
        class="pb-safe border-info-soft bg-info-soft text-info-soft-foreground fixed inset-x-0 bottom-16 z-50 mx-auto flex w-[calc(100%-1.5rem)] max-w-sm items-center gap-3 rounded-xl border px-4 py-3 shadow-lg md:bottom-4"
        role="status"
    >
        <RefreshCw class="size-4 shrink-0" aria-hidden="true" />
        <p class="flex-1 text-sm">ODA CRM-ის ახალი ვერსია მზადაა.</p>
        <Button size="sm" variant="info" @click="applyUpdate">განახლება</Button>
        <button type="button" aria-label="დახურვა" @click="dismissed = true">
            <X class="size-4" />
        </button>
    </div>
</template>
