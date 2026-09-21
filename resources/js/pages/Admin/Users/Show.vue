<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import StatusBadge from '@/components/StatusBadge.vue';
import type { StatusTone } from '@/types';

type PermissionGroup = {
    key: string;
    label: string;
    permissions: string[];
};

type EffectivePermission = {
    permission: string;
    group: string;
    granted: boolean;
    source: 'role' | 'direct_grant' | 'denied' | 'none';
    via_role: string | null;
    denial_id: string | null;
};

const props = defineProps<{
    targetUser: { id: string; name: string; email: string };
    roles: string[];
    assignedRoles: string[];
    permissionGroups: PermissionGroup[];
    effectivePermissions: EffectivePermission[];
    canManageRoles: boolean;
    canManageOverrides: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'წვდომის მართვა' } });

const SOURCE_LABEL: Record<string, string> = {
    role: 'როლიდან',
    direct_grant: 'პირდაპირი წვდომა',
    denied: 'აღკვეთილია',
};
const SOURCE_TONE: Record<string, StatusTone> = {
    role: 'info',
    direct_grant: 'success',
    denied: 'destructive',
};

const availableRolesToAssign = computed(() => props.roles.filter((role) => !props.assignedRoles.includes(role)));

const allPermissionNames = computed(() => props.permissionGroups.flatMap((group) => group.permissions));

const assignRoleForm = useForm({ role: '' });
function submitAssignRole() {
    assignRoleForm.post(`/admin/users/${props.targetUser.id}/roles`, {
        preserveScroll: true,
        onSuccess: () => assignRoleForm.reset(),
    });
}

const removeRoleForm = useForm({ role: '', reason: '' });
function submitRemoveRole(role: string) {
    removeRoleForm.role = role;
    removeRoleForm.delete(`/admin/users/${props.targetUser.id}/roles`, {
        preserveScroll: true,
        onSuccess: () => removeRoleForm.reset(),
    });
}

const grantForm = useForm({ permission: '' });
function submitGrant() {
    grantForm.post(`/admin/users/${props.targetUser.id}/overrides/grant`, {
        preserveScroll: true,
        onSuccess: () => grantForm.reset(),
    });
}

function submitRevoke(permission: string) {
    useForm({ permission }).post(`/admin/users/${props.targetUser.id}/overrides/revoke`, { preserveScroll: true });
}

function submitRemoveDenial(denialId: string) {
    useForm({}).delete(`/admin/users/${props.targetUser.id}/denials/${denialId}`, { preserveScroll: true });
}

const denyForm = useForm({ permission: '', reason: '' });
function submitDeny() {
    denyForm.post(`/admin/users/${props.targetUser.id}/denials`, {
        preserveScroll: true,
        onSuccess: () => denyForm.reset(),
    });
}
</script>

<template>
    <Head :title="`წვდომა — ${targetUser.name}`" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">{{ targetUser.name }}</h1>
            <p class="text-muted-foreground text-sm">{{ targetUser.email }}</p>
        </div>

        <section class="border-border bg-card flex flex-col gap-3 rounded-xl border p-4">
            <h2 class="font-medium">როლები</h2>
            <div class="flex flex-wrap gap-2">
                <div v-for="role in assignedRoles" :key="role" class="flex items-center gap-1">
                    <StatusBadge :label="role" tone="info" />
                    <Button
                        v-if="canManageRoles"
                        variant="ghost"
                        size="sm"
                        :disabled="removeRoleForm.processing"
                        @click="submitRemoveRole(role)"
                        >მოხსნა</Button
                    >
                </div>
                <StatusBadge v-if="assignedRoles.length === 0" label="როლის გარეშე" tone="neutral" />
            </div>
            <p v-if="removeRoleForm.errors.reason" class="text-destructive text-sm">{{ removeRoleForm.errors.reason }}</p>

            <form v-if="canManageRoles && availableRolesToAssign.length > 0" class="flex flex-wrap items-center gap-2" @submit.prevent="submitAssignRole">
                <select v-model="assignRoleForm.role" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option value="" disabled>როლის მინიჭება...</option>
                    <option v-for="role in availableRolesToAssign" :key="role" :value="role">{{ role }}</option>
                </select>
                <Button type="submit" size="sm" :disabled="assignRoleForm.processing">მინიჭება</Button>
            </form>
        </section>

        <section v-if="canManageOverrides" class="border-border bg-card flex flex-col gap-4 rounded-xl border p-4">
            <h2 class="font-medium">პირდაპირი წვდომის მართვა</h2>
            <p class="text-muted-foreground text-sm">
                დაუმატეთ უფლება, რომელსაც მომხმარებლის როლი არ იძლევა, ან აღკვეთეთ უფლება, რომელსაც როლი მისცემდა.
                აღკვეთა ყოველთვის იმარჯვებს — მაშინაც კი, თუ მომხმარებელს პლატფორმის ადმინისტრატორის წვდომა აქვს.
            </p>

            <form class="grid gap-2 md:grid-cols-[1fr_auto]" @submit.prevent="submitGrant">
                <select v-model="grantForm.permission" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option value="" disabled>უფლების პირდაპირ მინიჭება...</option>
                    <option v-for="permission in allPermissionNames" :key="permission" :value="permission">{{ permission }}</option>
                </select>
                <Button type="submit" size="sm" :disabled="grantForm.processing">მინიჭება</Button>
            </form>

            <form class="grid gap-2 md:grid-cols-[1fr_1fr_auto]" @submit.prevent="submitDeny">
                <select v-model="denyForm.permission" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option value="" disabled>უფლების აღკვეთა...</option>
                    <option v-for="permission in allPermissionNames" :key="permission" :value="permission">{{ permission }}</option>
                </select>
                <input
                    v-model="denyForm.reason"
                    type="text"
                    required
                    placeholder="მიზეზი"
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                />
                <Button type="submit" size="sm" variant="destructive" :disabled="denyForm.processing">აღკვეთა</Button>
            </form>
            <p v-if="denyForm.errors.reason" class="text-destructive text-sm">{{ denyForm.errors.reason }}</p>
        </section>

        <section class="border-border bg-card flex flex-col gap-2 rounded-xl border p-4">
            <h2 class="font-medium">ეფექტური უფლებები</h2>
            <p class="text-muted-foreground text-sm">
                ყველა უფლება, რომელიც ამ მომხმარებელს რეალურად აქვს (ან რომელიც აღკვეთილია), წყაროს მითითებით.
            </p>
            <div class="divide-border divide-y">
                <div
                    v-for="row in effectivePermissions"
                    :key="row.permission"
                    class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                >
                    <div class="min-w-0">
                        <p class="truncate font-mono text-xs">{{ row.permission }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ row.group }}<template v-if="row.via_role"> · {{ row.via_role }}</template>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge :label="SOURCE_LABEL[row.source] || row.source" :tone="SOURCE_TONE[row.source] || 'neutral'" />
                        <Button
                            v-if="canManageOverrides && row.source === 'direct_grant'"
                            variant="ghost"
                            size="sm"
                            @click="submitRevoke(row.permission)"
                            >მოხსნა</Button
                        >
                        <Button
                            v-if="canManageOverrides && row.source === 'denied' && row.denial_id"
                            variant="ghost"
                            size="sm"
                            @click="submitRemoveDenial(row.denial_id)"
                            >აღკვეთის მოხსნა</Button
                        >
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
