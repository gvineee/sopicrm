<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { clearProtectedData } from '@/lib/offlineQueue';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const props = defineProps<Props>();

// PWA-01 hard requirement: logout must clear this user's own offline
// drafts/photos so they never leak into whichever account uses this device
// next — this app's only real "user switch" boundary (no separate
// account-switch feature exists). The click is intercepted (preventDefault)
// so the wipe genuinely finishes — real IndexedDB deletes, not
// instantaneous — BEFORE the logout navigation actually starts, rather than
// racing a page unload that could cut the wipe off partway through.
const handleLogout = async (event: MouseEvent) => {
    event.preventDefault();

    const organizationId = props.user.organization_id as string | undefined;
    const userId = props.user.id as unknown as string | undefined;

    if (organizationId && userId) {
        await clearProtectedData(organizationId, userId);
    }

    router.flushAll();
    router.post(logout().url);
};
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
