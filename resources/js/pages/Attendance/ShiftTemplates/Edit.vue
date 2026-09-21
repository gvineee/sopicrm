<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ShiftTemplateForm from '@/components/Attendance/ShiftTemplateForm.vue';

type Site = { id: string; name: string };

type ShiftTemplate = {
    id: string;
    site_id: string | null;
    name: string;
    starts_at_local: string;
    ends_at_local: string;
    crosses_midnight: boolean;
    scheduled_days: string[];
    break_policy: { type: 'fixed' | 'scheduled'; minutes?: number; windows?: { key: string; start: string; end: string }[] };
    allowed_late_minutes: number;
    rounding_policy: Record<string, unknown>;
    requires_approval_by_role: string | null;
};

defineOptions({ layout: { mobileTitle: 'შაბლონის რედაქტირება' } });

defineProps<{ sites: Site[]; shiftTemplate: ShiftTemplate }>();
</script>

<template>
    <Head title="ცვლის შაბლონის რედაქტირება" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/attendance/shift-templates" class="text-muted-foreground text-sm hover:underline">← ცვლის შაბლონები</Link>
            <h1 class="mt-2 text-2xl font-semibold">{{ shiftTemplate.name }}</h1>
        </div>
        <ShiftTemplateForm :sites="sites" :shift-template="shiftTemplate" />
    </div>
</template>
