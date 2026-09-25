<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { userRoleLabel } from '@/lib/labels';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type AdminUser = {
    id: string;
    name: string;
    email: string;
    is_active: boolean;
    roles: string[];
};

defineProps<{
    users: AdminUser[];
}>();

defineOptions({ layout: { mobileTitle: 'მომხმარებლები' } });
</script>

<template>
    <Head title="მომხმარებლები და წვდომები" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">მომხმარებლები და წვდომები</h1>
            <p class="text-muted-foreground text-sm">
                ორგანიზაციის მომხმარებლები, მათი როლები და პირდაპირი წვდომის შეცვლები.
            </p>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="users.length === 0" title="მომხმარებელი არ არის" description="ამ ორგანიზაციაში მომხმარებელი ჯერ არ არსებობს." />
            <div v-else class="divide-border divide-y">
                <div v-for="user in users" :key="user.id" class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ user.name }}</p>
                        <p class="text-muted-foreground truncate text-sm">{{ user.email }}</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <StatusBadge v-for="role in user.roles" :key="role" :label="userRoleLabel(role)" tone="info" />
                            <StatusBadge v-if="user.roles.length === 0" label="როლის გარეშე" tone="neutral" />
                            <StatusBadge v-if="!user.is_active" label="გათიშული" tone="warning" />
                        </div>
                    </div>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="`/admin/users/${user.id}`">მართვა</Link>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
