<script setup lang="ts">
/**
 * BIO-01: shown wherever card/access UI could otherwise imply the CRM
 * controls a real BioStar device. First integration stage is read-only —
 * BioStar owns devices/cards/access, the CRM only copies user metadata and
 * events. A pending sync command here will never be dispatched to real
 * hardware until a write integration is pilot-confirmed and explicitly
 * turned on (see services/device-connector/README.md).
 */
import { ShieldAlert } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
</script>

<template>
    <Alert variant="default" class="border-amber-500/40 bg-amber-500/10">
        <ShieldAlert class="size-4 text-amber-600" aria-hidden="true" />
        <AlertTitle>ბარათები და დაშვება იმართება BioStar-ში</AlertTitle>
        <AlertDescription>
            ეს გვერდი მხოლოდ კითხულობს BioStar-ის მონაცემებს. ბარათის გაცემა/ხელახლა გაცემა/გაუქმება CRM-ში
            ინახავს ჩანაწერს და დაშვების ისტორიას, მაგრამ ავტომატურად არ ეგზავნება რეალურ მოწყობილობას — ბარათის
            რეალურად დამატება ან გაუქმება უშუალოდ BioStar-ში საჭიროა, სანამ ორმხრივი სინქრონიზაცია დადასტურებული არ
            იქნება.
        </AlertDescription>
    </Alert>
</template>
