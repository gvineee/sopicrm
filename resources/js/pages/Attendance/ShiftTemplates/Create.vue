<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ShiftTemplateForm from '@/components/Attendance/ShiftTemplateForm.vue';

type Site = { id: string; name: string };

defineOptions({ layout: { mobileTitle: 'შაბლონის დამატება' } });

defineProps<{ sites: Site[] }>();
</script>

<template>
    <Head title="ცვლის შაბლონის დამატება" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/attendance/shift-templates" class="text-muted-foreground text-sm hover:underline">← ცვლის შაბლონები</Link>
            <h1 class="mt-2 text-2xl font-semibold">ცვლის შაბლონის დამატება</h1>
        </div>
        <ShiftTemplateForm :sites="sites" />
    </div>
</template>
