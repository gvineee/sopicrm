<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '@/components/states/EmptyState.vue';

type ProjectSummary = { id: string; name: string; code: string | null };

defineProps<{
    projects: ProjectSummary[];
}>();

defineOptions({ layout: { mobileTitle: 'დღიური ჟურნალი' } });
</script>

<template>
    <Head title="დღიური ჟურნალი" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">დღიური ჟურნალი</h1>
            <p class="text-muted-foreground text-sm">აირჩიეთ პროექტი, რომლის დღიური ჩანაწერების ნახვაც/შევსებაც გსურთ.</p>
        </div>

        <EmptyState
            v-if="projects.length === 0"
            title="ხელმისაწვდომი პროექტი არ არის"
            description="დღიური ჟურნალის სანახავად საჭიროა აქტიური პროექტის წევრობა."
        />
        <div v-else class="border-border bg-card divide-border divide-y overflow-hidden rounded-xl border">
            <Link
                v-for="project in projects"
                :key="project.id"
                :href="`/projects/${project.id}/daily-journal`"
                class="hover:bg-muted/50 flex items-center justify-between gap-3 p-4"
            >
                <div>
                    <p class="font-medium">{{ project.name }}</p>
                    <p v-if="project.code" class="text-muted-foreground text-sm">{{ project.code }}</p>
                </div>
                <span class="text-muted-foreground text-sm">→</span>
            </Link>
        </div>
    </div>
</template>
