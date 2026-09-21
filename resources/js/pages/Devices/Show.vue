<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SimulatorModeBadge from '@/components/Devices/SimulatorModeBadge.vue';
import BioStarReadOnlyBanner from '@/components/Devices/BioStarReadOnlyBanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import type { StatusTone } from '@/types';

type Device = {
    id: string;
    name?: string | null;
    vendor?: string | null;
    site_id: string;
    site_name?: string | null;
    serial_number: string;
    device_identifier?: string | null;
    ip_address?: string | null;
    port?: number | null;
    mac_address?: string | null;
    model: string;
    firmware_version?: string | null;
    hardware_version?: string | null;
    connection_mode?: string | null;
    install_location?: string | null;
    reader_role: string;
    device_timezone: string;
    timezone?: string | null;
    enabled?: boolean;
    status: string;
    sync_status: string;
    last_heartbeat_at?: string | null;
    last_event_at?: string | null;
    last_seen_at?: string | null;
    last_sync_at?: string | null;
    connector_version?: string | null;
};

type SyncCommand = {
    id: string;
    command_type: string;
    target_entity_type: string;
    target_entity_id: string;
    command_version: number;
    status: string;
    attempts: number;
    last_error?: string | null;
    acknowledged_at?: string | null;
};

type ImportHealth = {
    checkpoint: { stream_epoch: number; last_native_event_id: number; last_confirmed_at: string | null } | null;
    open_anomalies: { data_gap: number; out_of_order_events: number; clock_drift: number };
    command_backlog: { pending: number; retry: number; failed: number; dead_letter: number };
};

const props = defineProps<{
    device: Device;
    resolvedStatus: string;
    capabilities: Array<{ key: string; value: unknown; read_at?: string | null }>;
    syncCommands: SyncCommand[];
    importHealth: ImportHealth;
    isSimulatorMode: boolean;
    biostarReadOnly: boolean;
    canOperateSimulator: boolean;
    canEdit: boolean;
}>();

defineOptions({ layout: { mobileTitle: 'მოწყობილობა' } });

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
const COMMAND_STATUS_TONE: Record<string, StatusTone> = {
    pending: 'warning',
    processing: 'info',
    succeeded: 'success',
    failed: 'destructive',
    retry: 'warning',
    dead_letter: 'destructive',
};
const COMMAND_STATUS_LABEL: Record<string, string> = {
    pending: 'მოლოდინში',
    processing: 'მუშავდება',
    succeeded: 'წარმატებული',
    failed: 'ჩავარდნილი',
    retry: 'ხელახლა ცდა',
    dead_letter: 'საბოლოოდ ჩავარდნილი',
};

const statusForm = useForm({ status: props.device.status });
const tickForm = useForm({});
const eventForm = useForm({
    native_event_id: '',
    stream_epoch: '1',
    card_type: 'EM',
    card_hex: '',
    direction: props.device.reader_role === 'unspecified' ? 'unspecified' : props.device.reader_role,
    event_code: 'access_granted',
});

function setStatus() {
    statusForm.post(`/devices/${props.device.id}/simulator/status`, { preserveScroll: true });
}

function runTick() {
    tickForm.post(`/devices/${props.device.id}/simulator/tick`, { preserveScroll: true });
}

function generateEvent() {
    eventForm.post(`/devices/${props.device.id}/simulator/generate-event`, {
        preserveScroll: true,
        onSuccess: () => eventForm.reset('native_event_id', 'card_hex'),
    });
}
</script>

<template>
    <Head :title="device.serial_number" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link href="/devices" class="text-muted-foreground text-sm hover:underline"
                    >← მოწყობილობები</Link
                >
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ device.name || device.serial_number }}</h1>
                    <SimulatorModeBadge v-if="isSimulatorMode" />
                </div>
                <p class="text-muted-foreground text-sm">
                    {{ device.model }} · {{ device.site_name || 'ობიექტი მიუთითებელია' }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button v-if="canEdit" as-child variant="outline" size="sm">
                    <Link :href="`/devices/${device.id}/edit`">რედაქტირება</Link>
                </Button>
                <StatusBadge
                    :label="STATUS_LABEL[resolvedStatus] || resolvedStatus"
                    :tone="STATUS_TONE[resolvedStatus] || 'neutral'"
                />
            </div>
        </div>

        <BioStarReadOnlyBanner v-if="biostarReadOnly" />

        <div class="grid gap-5 lg:grid-cols-3">
            <section class="border-border bg-card rounded-xl border p-5 lg:col-span-2">
                <h2 class="font-semibold">მოწყობილობის მონაცემები</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted-foreground">Serial number</dt><dd>{{ device.serial_number }}</dd></div>
                    <div><dt class="text-muted-foreground">Device identifier</dt><dd>{{ device.device_identifier || '—' }}</dd></div>
                    <div><dt class="text-muted-foreground">IP / Port</dt><dd>{{ device.ip_address || '—' }}{{ device.port ? `:${device.port}` : '' }}</dd></div>
                    <div><dt class="text-muted-foreground">MAC</dt><dd>{{ device.mac_address || '—' }}</dd></div>
                    <div>
                        <dt class="text-muted-foreground">Firmware</dt>
                        <dd>{{ device.firmware_version || 'დასადასტურებელია პილოტზე' }}</dd>
                    </div>
                    <div><dt class="text-muted-foreground">Hardware</dt><dd>{{ device.hardware_version || '—' }}</dd></div>
                    <div><dt class="text-muted-foreground">Connection</dt><dd>{{ device.connection_mode || 'gateway' }}</dd></div>
                    <div>
                        <dt class="text-muted-foreground">მდებარეობა</dt>
                        <dd>{{ device.install_location || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Reader-ის როლი</dt>
                        <dd>
                            {{
                                device.reader_role === 'in'
                                    ? 'IN'
                                    : device.reader_role === 'out'
                                      ? 'OUT'
                                      : 'IN/OUT (ერთი reader)'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">დროის სარტყელი</dt>
                        <dd>{{ device.device_timezone }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">ბოლო heartbeat</dt>
                        <dd>{{ device.last_heartbeat_at || 'არასდროს' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">ბოლო მოვლენა</dt>
                        <dd>{{ device.last_event_at || 'არასდროს' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">სინქრონიზაცია</dt>
                        <dd>{{ device.sync_status }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Connector ვერსია</dt>
                        <dd>{{ device.connector_version || '—' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">Capability snapshot</h2>
                <p class="text-muted-foreground mt-1 text-xs">
                    ლიმიტები არასდროს არის გამოგონილი — პირდაპირ მოწყობილობიდან/სიმულატორიდან წაკითხულია.
                </p>
                <div v-if="capabilities.length" class="mt-4 space-y-2 text-sm">
                    <div
                        v-for="cap in capabilities"
                        :key="cap.key"
                        class="border-border flex items-center justify-between gap-2 rounded-lg border p-2"
                    >
                        <span class="text-muted-foreground truncate">{{ cap.key }}</span>
                        <span class="truncate font-mono text-xs">{{ JSON.stringify(cap.value) }}</span>
                    </div>
                </div>
                <p v-else class="text-muted-foreground mt-3 text-sm">Capability ჯერ არ არის წაკითხული.</p>
            </section>
        </div>

        <section
            v-if="isSimulatorMode && canOperateSimulator"
            class="border-warning/40 bg-card rounded-xl border p-5"
        >
            <div class="flex items-center gap-2">
                <h2 class="font-semibold">სიმულატორის კონტროლი</h2>
                <SimulatorModeBadge />
            </div>
            <p class="text-muted-foreground mt-1 text-sm">
                ეს კონტროლები არსებობს მხოლოდ სატესტო რეჟიმში და არასდროს მოქმედებს რეალურ hardware-ზე.
            </p>

            <div class="mt-4 grid gap-5 md:grid-cols-2">
                <div class="grid gap-3">
                    <Label>მოწყობილობის სტატუსის სიმულაცია</Label>
                    <div class="flex flex-wrap gap-2">
                        <select
                            v-model="statusForm.status"
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="online">ონლაინ</option>
                            <option value="offline">ოფლაინ</option>
                            <option value="degraded">არასტაბილური</option>
                            <option value="unknown">უცნობი</option>
                        </select>
                        <Button size="sm" :disabled="statusForm.processing" @click="setStatus"
                            >მიღება</Button
                        >
                    </div>

                    <Button
                        class="mt-2 w-fit"
                        variant="outline"
                        size="sm"
                        :disabled="tickForm.processing"
                        @click="runTick"
                        >Connector tick-ის გაშვება (მოლოდინში მყოფი ბრძანებების დამუშავება)</Button
                    >
                </div>

                <form class="grid gap-2" @submit.prevent="generateEvent">
                    <Label>ტესტური access event-ის გენერაცია</Label>
                    <div class="grid grid-cols-2 gap-2">
                        <Input
                            v-model="eventForm.native_event_id"
                            type="number"
                            min="1"
                            placeholder="Native event ID"
                            required
                        />
                        <Input
                            v-model="eventForm.stream_epoch"
                            type="number"
                            min="1"
                            placeholder="Stream epoch"
                            required
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <Input v-model="eventForm.card_type" placeholder="Card type (EM/MIFARE)" />
                        <Input v-model="eventForm.card_hex" placeholder="Card hex (არასავალდებულო)" />
                    </div>
                    <select
                        v-model="eventForm.direction"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="in">IN</option>
                        <option value="out">OUT</option>
                        <option value="unspecified">UNSPECIFIED</option>
                    </select>
                    <Button type="submit" size="sm" :disabled="eventForm.processing"
                        >მოვლენის დაფიქსირება</Button
                    >
                </form>
            </div>
        </section>

        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">მოვლენების იმპორტის მდგომარეობა</h2>
            <p class="text-muted-foreground mt-1 text-sm">
                checkpoint, ღია data-gap/თანმიმდევრობის ანომალიები და ჩავარდნილი sync ბრძანებები — ბოლო
                წარმატებული იმპორტისა და backlog-ის რეალური სურათი.
            </p>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">ბოლო დადასტურებული checkpoint</dt>
                    <dd>{{ importHealth.checkpoint?.last_confirmed_at || 'არასდროს' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">ბოლო native event id / stream epoch</dt>
                    <dd>
                        {{ importHealth.checkpoint?.last_native_event_id ?? '—' }} /
                        {{ importHealth.checkpoint?.stream_epoch ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">ღია ანომალიები</dt>
                    <dd>
                        data gap: {{ importHealth.open_anomalies.data_gap }} · თანმიმდევრობა:
                        {{ importHealth.open_anomalies.out_of_order_events }} · საათის აცდენა:
                        {{ importHealth.open_anomalies.clock_drift }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Command backlog</dt>
                    <dd>
                        მოლოდინში: {{ importHealth.command_backlog.pending }} · ხელახლა ცდა:
                        {{ importHealth.command_backlog.retry }} · ჩავარდნილი:
                        {{ importHealth.command_backlog.failed }} · საბოლოოდ ჩავარდნილი:
                        {{ importHealth.command_backlog.dead_letter }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="border-border bg-card rounded-xl border p-5">
            <h2 class="font-semibold">Sync command queue — desired vs acknowledged</h2>
            <p class="text-muted-foreground mt-1 text-sm">
                „მოლოდინში"/„ხელახლა ცდა" სტატუსი ნიშნავს, რომ ბრძანება ჯერ არ დადასტურებულა
                მოწყობილობის მიერ — ეს არასდროს გამოისახება როგორც დასრულებული.
                <template v-if="biostarReadOnly">
                    ამჟამად read-only რეჟიმშია: „მოლოდინში" დარჩენილი ბრძანებები არასდროს გაეგზავნება რეალურ
                    BioStar-ს ავტომატურად — საჭირო ცვლილება უშუალოდ BioStar-ში შეიტანეთ.
                </template>
            </p>
            <EmptyState
                v-if="syncCommands.length === 0"
                class="mt-4"
                title="ბრძანებები ჯერ არ არის"
                description="ბარათის გაცემა/გაუქმება აქ შექმნის sync command-ს."
            />
            <div v-else class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="text-muted-foreground text-left">
                        <tr>
                            <th class="pb-2 font-medium">ტიპი</th>
                            <th class="pb-2 font-medium">ვერსია</th>
                            <th class="pb-2 font-medium">სტატუსი</th>
                            <th class="pb-2 font-medium">მცდელობები</th>
                            <th class="pb-2 font-medium">დადასტურდა</th>
                            <th class="pb-2 font-medium">შეცდომა</th>
                        </tr>
                    </thead>
                    <tbody class="divide-border divide-y">
                        <tr v-for="command in syncCommands" :key="command.id">
                            <td class="py-2">{{ command.command_type }}</td>
                            <td class="py-2">{{ command.command_version }}</td>
                            <td class="py-2">
                                <StatusBadge
                                    :label="COMMAND_STATUS_LABEL[command.status] || command.status"
                                    :tone="COMMAND_STATUS_TONE[command.status] || 'neutral'"
                                />
                            </td>
                            <td class="py-2">{{ command.attempts }}</td>
                            <td class="py-2">{{ command.acknowledged_at || '—' }}</td>
                            <td class="text-muted-foreground py-2 text-xs">{{ command.last_error || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
