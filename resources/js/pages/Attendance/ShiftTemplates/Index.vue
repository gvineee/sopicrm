<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/states/EmptyState.vue';

type ShiftTemplate = {
    id: string;
    name: string;
    site_name: string | null;
    starts_at_local: string;
    ends_at_local: string;
    crosses_midnight: boolean;
    allowed_late_minutes: number;
};

defineOptions({ layout: { mobileTitle: 'ცვლის შაბლონები' } });

defineProps<{
    shiftTemplates: ShiftTemplate[];
    canManage: boolean;
}>();

function destroy(template: ShiftTemplate) {
    if (!confirm(`წავშალო შაბლონი „${template.name}“?`)) return;
    router.delete(`/attendance/shift-templates/${template.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="ცვლის შაბლონები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">ცვლის შაბლონები</h1>
                <p class="text-muted-foreground text-sm">სამუშაო საათები, შესვენების პოლიტიკა და დაშვებული დაგვიანება ობიექტების მიხედვით.</p>
            </div>
            <Button v-if="canManage" as-child><Link href="/attendance/shift-templates/create">შაბლონის დამატება</Link></Button>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="shiftTemplates.length === 0" title="ცვლის შაბლონი არ არის დამატებული" description="დაამატეთ პირველი ცვლის შაბლონი." />
            <div v-else class="divide-border divide-y">
                <div
                    v-for="template in shiftTemplates"
                    :key="template.id"
                    class="grid gap-3 p-4 md:grid-cols-[1fr_140px_140px_auto] md:items-center"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ template.name }}</p>
                        <p class="text-muted-foreground truncate text-sm">{{ template.site_name || 'ყველა ობიექტი' }}</p>
                    </div>
                    <p class="text-muted-foreground text-sm">{{ template.starts_at_local }}–{{ template.ends_at_local }}</p>
                    <p class="text-muted-foreground text-sm">{{ template.crosses_midnight ? 'ღამის ცვლა' : 'დღის ცვლა' }}</p>
                    <div v-if="canManage" class="flex gap-2">
                        <Link :href="`/attendance/shift-templates/${template.id}/edit`" class="text-sm hover:underline">რედაქტირება</Link>
                        <button type="button" class="text-destructive text-sm hover:underline" @click="destroy(template)">წაშლა</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
