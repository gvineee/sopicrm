<script setup lang="ts">
/**
 * spec section 6: "Simulator-ის ეკრანს მუდმივად ეწეროს "სატესტო რეჟიმი"."
 * Every screen that shows simulator-sourced device data or simulator
 * controls must render this — never silently imply real hardware.
 */
import { FlaskConical } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
</script>

<template>
    <Badge variant="warning" class="gap-1">
        <FlaskConical class="size-3" aria-hidden="true" />
        სატესტო რეჟიმი — Simulator
    </Badge>
</template>
