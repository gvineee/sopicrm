<script setup lang="ts">
/**
 * iOS Add-to-Home-Screen guide (spec 17). iOS Safari has no
 * `beforeinstallprompt` at all, so this is a purely instructional
 * component — it never claims to trigger an install itself, and it must
 * not be shown once already standalone (feature-detected) or on a non-iOS
 * device (shown only when the parent decides iOS is likely, via
 * usePwaInstall's `isLikelyIosSafari`, itself feature- not UA-string-based
 * beyond the narrow signal documented there).
 */
import { Share, SquarePlus } from '@lucide/vue';
import { ref } from 'vue';
import { usePwaInstall } from '@/composables/usePwaInstall';

const { isStandalone } = usePwaInstall();
const open = ref(false);
</script>

<template>
    <div v-if="!isStandalone">
        <button
            type="button"
            class="text-muted-foreground text-xs underline-offset-2 hover:underline"
            @click="open = !open"
        >
            iPhone/iPad-ზე დაყენება
        </button>

        <ol
            v-if="open"
            class="text-muted-foreground mt-2 max-w-72 list-decimal space-y-1.5 pl-4 text-xs"
        >
            <li class="flex items-center gap-1.5">
                გახსენით Safari-ის
                <Share class="inline size-3.5" aria-hidden="true" />
                „გაზიარების“ ღილაკი
            </li>
            <li class="flex items-center gap-1.5">
                აირჩიეთ
                <SquarePlus class="inline size-3.5" aria-hidden="true" />
                „Add to Home Screen“ („მთავარ ეკრანზე დამატება“)
            </li>
            <li>საჭიროების შემთხვევაში აირჩიეთ „Open as Web App“</li>
            <li>დააჭირეთ „Add“ ზედა მარჯვენა კუთხეში</li>
        </ol>
    </div>
</template>
