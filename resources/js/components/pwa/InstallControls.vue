<script setup lang="ts">
/**
 * Picks the right install affordance per platform: the real
 * beforeinstallprompt-driven button where supported, the iOS instructional
 * guide on likely-iOS Safari, nothing once standalone. See
 * composables/usePwaInstall.ts for why this split is feature-detected, not
 * user-agent-gated for the Android path.
 */
import { isLikelyIosSafari, usePwaInstall } from '@/composables/usePwaInstall';
import IosInstallGuide from '@/components/pwa/IosInstallGuide.vue';
import InstallOdaButton from '@/components/pwa/InstallOdaButton.vue';

const { isStandalone } = usePwaInstall();
const showIosGuide = isLikelyIosSafari();
</script>

<template>
    <div v-if="!isStandalone">
        <IosInstallGuide v-if="showIosGuide" />
        <InstallOdaButton v-else />
    </div>
</template>
