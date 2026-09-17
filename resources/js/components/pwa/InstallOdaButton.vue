<script setup lang="ts">
/**
 * "დააყენე ODA" button (spec 17). Only ever calls the real
 * `beforeinstallprompt` flow, and only when the browser actually fired that
 * event (feature detection, never a fake/simulated Android-style prompt —
 * spec 17: "beforeinstallprompt-ს მხოლოდ მხარდაჭერისა და მიღებული
 * event-ის შემთხვევაში"). When the event was never fired (unsupported
 * browser, criteria not met, or a per-browser install path with no JS
 * hook), it shows a short "your browser's menu" instruction instead of
 * hiding entirely — so there's always a way forward. Hidden outright once
 * standalone (already installed) is detected.
 */
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { usePwaInstall } from '@/composables/usePwaInstall';

const { canInstall, isStandalone, promptInstall } = usePwaInstall();
const showMenuHint = ref(false);
const dismissed = ref(false);

const showFallbackButton = computed(
    () => !isStandalone.value && !canInstall.value && !dismissed.value,
);

async function handleInstallClick() {
    const outcome = await promptInstall();
    if (outcome === null) {
        showMenuHint.value = true;
    }
}
</script>

<template>
    <div v-if="!isStandalone">
        <Button
            v-if="canInstall"
            variant="outline"
            size="sm"
            @click="handleInstallClick"
        >
            <Download class="size-4" aria-hidden="true" />
            დააყენე ODA
        </Button>

        <button
            v-else-if="showFallbackButton"
            type="button"
            class="text-muted-foreground text-xs underline-offset-2 hover:underline"
            @click="showMenuHint = true"
        >
            როგორ დავაყენო ODA?
        </button>

        <p
            v-if="showMenuHint"
            class="text-muted-foreground mt-1 max-w-64 text-xs"
        >
            გახსენით ბრაუზერის მენიუ (⋮ ან ...) და აირჩიეთ „დაინსტალირება“ ან
            „მთავარ ეკრანზე დამატება“.
            <button
                type="button"
                class="ml-1 underline"
                @click="
                    showMenuHint = false;
                    dismissed = true;
                "
            >
                გასაგებია
            </button>
        </p>
    </div>
</template>
