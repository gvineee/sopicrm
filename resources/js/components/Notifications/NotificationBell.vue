<script setup lang="ts">
/**
 * NOTIFY-01: real notification bell — polls the user's own notifications
 * every 30s (no websocket/broadcast infra exists in this codebase) and
 * lets them mark one/all read. `deep_link` navigates via a plain
 * navigation (not an Inertia <Link>) since it may point at a route this
 * lightweight component has no wayfinder-generated helper for.
 */
import { api } from '@/lib/api';
import { formatDateTime } from '@/lib/labels';
import { Bell } from '@lucide/vue';
import { onMounted, onUnmounted, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The Georgian names for these already existed server-side
 * (App\Domain\Notifications\Support\NotificationType::labels()) but were used
 * only by the preferences page, never by this dropdown.
 */
const NOTIFICATION_TYPE_LABEL: Record<string, string> = {
    task_assigned: 'დავალების მინიჭება',
    task_returned: 'დავალების დაბრუნება',
    mention: 'ხსენება კომენტარში',
    overdue: 'დავალების ვადაგადაცილება',
    tool_return_due: 'ხელსაწყოს დაბრუნების ვადა',
    timesheet_exception: 'ტაბელის გამონაკლისი',
    device_fault: 'მოწყობილობის ხარვეზი',
};

type NotificationItem = {
    id: string;
    type: string;
    title: string;
    message: string;
    deep_link: string | null;
    read_at: string | null;
    created_at: string | null;
};

const items = ref<NotificationItem[]>([]);
const unreadCount = ref(0);
let pollHandle: ReturnType<typeof setInterval> | undefined;

async function load() {
    try {
        const data = await api.get<{ notifications: NotificationItem[]; unread_count: number }>('/notifications');
        items.value = data.notifications;
        unreadCount.value = data.unread_count;
    } catch {
        // Silent — a failed poll should never break the rest of the page;
        // the next 30s poll simply tries again.
    }
}

async function markRead(item: NotificationItem) {
    if (item.read_at !== null) return;
    item.read_at = new Date().toISOString();
    unreadCount.value = Math.max(0, unreadCount.value - 1);
    await api.post(`/notifications/${item.id}/read`);
}

async function markAllRead() {
    items.value = items.value.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() }));
    unreadCount.value = 0;
    await api.post('/notifications/read-all');
}

onMounted(() => {
    load();
    pollHandle = setInterval(load, 30000);
});
onUnmounted(() => clearInterval(pollHandle));
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="border-sidebar-border bg-sidebar text-muted-foreground hover:bg-sidebar-accent relative flex h-9 w-9 items-center justify-center rounded-md border"
                aria-label="შეტყობინებები"
            >
                <Bell class="size-4" aria-hidden="true" />
                <span
                    v-if="unreadCount > 0"
                    class="bg-destructive text-destructive-foreground absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px]"
                >
                    {{ unreadCount > 9 ? '9+' : unreadCount }}
                </span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent class="w-80 p-0" align="end">
            <div class="border-border flex items-center justify-between border-b px-3 py-2">
                <span class="text-sm font-medium">შეტყობინებები</span>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground text-xs"
                    @click="markAllRead"
                >
                    ყველას წაკითხულად მონიშვნა
                </button>
            </div>
            <div class="max-h-96 overflow-y-auto">
                <p v-if="items.length === 0" class="text-muted-foreground px-3 py-4 text-sm">
                    შეტყობინება არ არის
                </p>
                <a
                    v-for="item in items"
                    :key="item.id"
                    :href="item.deep_link ?? '#'"
                    class="hover:bg-accent block border-b px-3 py-2 text-sm last:border-b-0"
                    :class="item.read_at === null ? 'bg-accent/40' : ''"
                    @click="markRead(item)"
                >
                    <span class="block font-medium">{{ item.title }}</span>
                    <span class="text-muted-foreground block text-xs">{{ item.message }}</span>
                    <!-- Audit A21: eight notifications showed the same text for
                         two devices with nothing to tell the occurrences apart.
                         `created_at` and `type` were already fetched by the
                         controller and simply never rendered, so a reader could
                         not tell how many separate incidents they were looking
                         at. -->
                    <span class="text-muted-foreground mt-0.5 block text-[11px]">
                        {{ formatDateTime(item.created_at) }}
                        <template v-if="NOTIFICATION_TYPE_LABEL[item.type]"> · {{ NOTIFICATION_TYPE_LABEL[item.type] }}</template>
                    </span>
                </a>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
