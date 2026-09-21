<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { reactive } from 'vue';

type PermissionGroup = {
    key: string;
    label: string;
    permissions: string[];
};

type Role = {
    name: string;
    permission_groups: PermissionGroup[];
};

defineProps<{
    roles: Role[];
}>();

defineOptions({ layout: { mobileTitle: 'როლები' } });

const open = reactive<Record<string, boolean>>({});
</script>

<template>
    <Head title="როლები" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">როლები</h1>
            <p class="text-muted-foreground text-sm">
                ეს არის სისტემის ფიქსირებული 11 როლი და მათი უფლებები, დაჯგუფებული ფუნქციონალის მიხედვით. ეს გვერდი
                მხოლოდ სანახავია — როლის უფლებების შეცვლა კოდის დონეზეა მართული. კონკრეტული მომხმარებლისთვის დამატებითი
                წვდომის მინიჭება ან აღკვეთა შესაძლებელია „მომხმარებლები და წვდომები“ გვერდიდან.
            </p>
        </div>

        <div class="border-border bg-card divide-border divide-y overflow-hidden rounded-xl border">
            <div v-for="role in roles" :key="role.name" class="p-4">
                <button
                    type="button"
                    class="flex w-full items-center justify-between text-left"
                    @click="open[role.name] = !open[role.name]"
                >
                    <span class="font-medium">{{ role.name }}</span>
                    <span class="text-muted-foreground text-sm">{{ open[role.name] ? 'დახურვა' : 'გახსნა' }}</span>
                </button>

                <div v-if="open[role.name]" class="mt-3 flex flex-col gap-3">
                    <div v-if="role.permission_groups.length === 0" class="text-muted-foreground text-sm">
                        ამ როლს უფლებები მინიჭებული არ აქვს.
                    </div>
                    <div v-for="group in role.permission_groups" :key="group.key">
                        <p class="text-sm font-medium">{{ group.label }}</p>
                        <ul class="text-muted-foreground mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm">
                            <li v-for="permission in group.permissions" :key="permission" class="font-mono text-xs">
                                {{ permission }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
