<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SimulatorModeBadge from '@/components/Devices/SimulatorModeBadge.vue';
import BioStarReadOnlyBanner from '@/components/Devices/BioStarReadOnlyBanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import type { StatusTone } from '@/types';

type Device = {
    id: string;
    site_id: string;
    site_name?: string | null;
    serial_number: string;
    model: string;
    reader_role: string;
    status: string;
    sync_status: string;
    resolved_status: string;
    last_heartbeat_at?: string | null;
};

type PaginatedDevices = {
    data: Device[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

defineOptions({ layout: { mobileTitle: 'მოწყობილობები' } });

defineProps<{
    devices: PaginatedDevices;
    filters: { search?: string; status?: string; site_id?: string };
    sites: Array<{ id: string; name: string }>;
    canManage: boolean;
    isSimulatorMode: boolean;
    biostarReadOnly: boolean;
}>();

const STATUS_TONE: Record<string, StatusTone> = {
    online: 'success',
    offline: 'destructive',
    degraded: 'warning',
    unknown: 'neutral',
};

const STATUS_LABEL: Record<string, string> = {
    online: 'ონლაინ',
    offline: 'ოფლაინ',
    degraded: 'არასტაბილური',
    unknown: 'უცნობი',
};

const SYNC_LABEL: Record<string, string> = {
    in_sync: 'სინქრონიზებული',
    pending: 'სინქრონიზაცია მიმდინარეობს',
    error: 'სინქრონიზაციის შეცდომა',
};

const READER_ROLE_LABEL: Record<string, string> = {
    in: 'შესვლა (IN)',
    out: 'გასვლა (OUT)',
    unspecified: 'განსაზღვრული არაა',
    first_last: 'აღრიცხვა (პირველი/ბოლო)',
    access_only: 'მხოლოდ დაშვება',
};
</script>

<template>
    <Head title="მოწყობილობები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div>
                    <h1 class="text-2xl font-semibold">მოწყობილობები</h1>
                    <p class="text-muted-foreground text-sm">
                        Suprema XPass 2 reader-ები, სტატუსი და სინქრონიზაცია.
                    </p>
                </div>
                <SimulatorModeBadge v-if="isSimulatorMode" />
            </div>
            <Button v-if="canManage" as-child
                ><Link href="/devices/create">მოწყობილობის დამატება</Link></Button
            >
        </div>

        <BioStarReadOnlyBanner v-if="biostarReadOnly" />

        <form
            method="get"
            action="/devices"
            class="border-border bg-card grid gap-3 rounded-xl border p-4 md:grid-cols-3"
        >
            <Input
                name="search"
                :default-value="filters.search"
                placeholder="სერიული ნომერი"
            />
            <select
                name="status"
                :value="filters.status"
                class="border-input bg-background h-9 rounded-md border px-3 text-sm"
            >
                <option value="">ყველა სტატუსი</option>
                <option value="online">ონლაინ</option>
                <option value="offline">ოფლაინ</option>
                <option value="degraded">არასტაბილური</option>
                <option value="unknown">უცნობი</option>
            </select>
            <div class="flex gap-2">
                <select
                    name="site_id"
                    :value="filters.site_id"
                    class="border-input bg-background h-9 min-w-0 flex-1 rounded-md border px-3 text-sm"
                >
                    <option value="">ყველა ობიექტი</option>
                    <option v-for="site in sites" :key="site.id" :value="site.id">
                        {{ site.name }}
                    </option>
                </select>
                <Button type="submit" variant="outline">ძიება</Button>
            </div>
        </form>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState
                v-if="devices.data.length === 0"
                title="მოწყობილობა ვერ მოიძებნა"
                description="ჯერ არცერთი reader არ არის დარეგისტრირებული ან ფილტრს არაფერი შეესაბამება."
            />
            <div v-else class="divide-border divide-y">
                <Link
                    v-for="device in devices.data"
                    :key="device.id"
                    :href="`/devices/${device.id}`"
                    class="hover:bg-muted/40 grid gap-2 p-4 transition-colors md:grid-cols-[1fr_160px_160px_160px] md:items-center"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ device.serial_number }}
                        </p>
                        <p class="text-muted-foreground truncate text-sm">
                            {{ device.model }} ·
                            {{ device.site_name || 'ობიექტი მიუთითებელია' }}
                        </p>
                    </div>
                    <p class="text-muted-foreground text-sm">
                        {{ READER_ROLE_LABEL[device.reader_role] || device.reader_role }}
                    </p>
                    <StatusBadge
                        :label="STATUS_LABEL[device.resolved_status] || device.resolved_status"
                        :tone="STATUS_TONE[device.resolved_status] || 'neutral'"
                    />
                    <p class="text-muted-foreground text-sm">
                        {{ SYNC_LABEL[device.sync_status] || device.sync_status }}
                    </p>
                </Link>
            </div>
        </div>

        <div class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground"
                >გვერდი {{ devices.current_page }} / {{ devices.last_page }}</span
            >
            <div class="flex gap-2">
                <Button
                    v-if="devices.prev_page_url"
                    as-child
                    variant="outline"
                    size="sm"
                    ><Link :href="devices.prev_page_url">წინა</Link></Button
                >
                <Button
                    v-if="devices.next_page_url"
                    as-child
                    variant="outline"
                    size="sm"
                    ><Link :href="devices.next_page_url">შემდეგი</Link></Button
                >
            </div>
        </div>
    </div>
</template>
