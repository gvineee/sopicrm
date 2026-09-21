<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Device = {
    id: string;
    name?: string | null;
    vendor?: string | null;
    site_id: string;
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
};

const props = defineProps<{
    device: Device;
    sites: Array<{ id: string; name: string }>;
}>();

defineOptions({ layout: { mobileTitle: 'მოწყობილობის რედაქტირება' } });

const form = useForm({
    name: props.device.name || '',
    vendor: props.device.vendor || 'suprema',
    site_id: props.device.site_id,
    serial_number: props.device.serial_number,
    device_identifier: props.device.device_identifier || '',
    ip_address: props.device.ip_address || '',
    port: props.device.port?.toString() || '',
    mac_address: props.device.mac_address || '',
    model: props.device.model,
    firmware_version: props.device.firmware_version || '',
    hardware_version: props.device.hardware_version || '',
    connection_mode: props.device.connection_mode || 'gateway',
    install_location: props.device.install_location || '',
    reader_role: props.device.reader_role,
    device_timezone: props.device.device_timezone,
    timezone: props.device.timezone || props.device.device_timezone,
    enabled: props.device.enabled !== false,
});

function submit() {
    form
        .transform((data) => ({
            ...data,
            firmware_version: data.firmware_version || null,
            name: data.name || null,
            device_identifier: data.device_identifier || null,
            ip_address: data.ip_address || null,
            port: data.port || null,
            mac_address: data.mac_address || null,
            hardware_version: data.hardware_version || null,
            install_location: data.install_location || null,
            timezone: data.timezone || data.device_timezone,
            device_timezone: data.device_timezone || data.timezone,
        }))
        .patch(`/devices/${props.device.id}`);
}
</script>

<template>
    <Head title="მოწყობილობის რედაქტირება" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link :href="`/devices/${device.id}`" class="text-muted-foreground text-sm hover:underline">
                ← მოწყობილობა
            </Link>
            <h1 class="mt-2 text-2xl font-semibold">მოწყობილობის რედაქტირება</h1>
            <p class="text-muted-foreground text-sm">
                კავშირის და სინქრონიზაციის სტატუსები connector-იდან იმართება და ამ ფორმით არ იცვლება.
            </p>
        </div>

        <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2"><div class="grid gap-2"><Label>სახელი</Label><Input v-model="form.name" required /></div><div class="grid gap-2"><Label>მწარმოებელი</Label><Input v-model="form.vendor" required /></div></div>
            <div class="grid gap-2">
                <Label>ობიექტი</Label>
                <select v-model="form.site_id" required class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                    <option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }}</option>
                </select>
                <p v-if="form.errors.site_id" class="text-destructive text-sm">{{ form.errors.site_id }}</p>
            </div>

            <div class="grid gap-2">
                <Label>სერიული ნომერი / Device ID</Label>
                <Input v-model="form.serial_number" required />
                <p v-if="form.errors.serial_number" class="text-destructive text-sm">{{ form.errors.serial_number }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2"><div class="grid gap-2"><Label>მოწყობილობის იდენტიფიკატორი</Label><Input v-model="form.device_identifier" /></div><div class="grid gap-2"><Label>Hardware ვერსია</Label><Input v-model="form.hardware_version" /></div></div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>მოდელი</Label>
                    <Input v-model="form.model" required />
                </div>
                <div class="grid gap-2">
                    <Label>Firmware ვერსია</Label>
                    <Input v-model="form.firmware_version" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label>მდებარეობა / კარი</Label>
                <Input v-model="form.install_location" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3"><div class="grid gap-2"><Label>IP მისამართი</Label><Input v-model="form.ip_address" /></div><div class="grid gap-2"><Label>პორტი</Label><Input v-model="form.port" type="number" min="1" max="65535" /></div><div class="grid gap-2"><Label>MAC მისამართი</Label><Input v-model="form.mac_address" /></div></div>
            <div class="grid gap-4 sm:grid-cols-2"><div class="grid gap-2"><Label>კავშირის რეჟიმი</Label><select v-model="form.connection_mode" class="border-input bg-background h-9 rounded-md border px-3 text-sm"><option value="gateway">Gateway</option><option value="tcp">TCP</option><option value="udp">UDP</option><option value="other">სხვა</option></select></div><label class="flex items-center gap-2 pt-7 text-sm"><input v-model="form.enabled" type="checkbox" /> აქტიურია</label></div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>Reader-ის როლი</Label>
                    <select v-model="form.reader_role" class="border-input bg-background h-9 rounded-md border px-3 text-sm">
                        <option value="in">შესვლა (IN)</option>
                        <option value="out">გასვლა (OUT)</option>
                        <option value="unspecified">განსაზღვრული არაა</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>დროის სარტყელი</Label>
                    <Input v-model="form.device_timezone" required />
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">შენახვა</Button>
                <Button as-child type="button" variant="outline">
                    <Link :href="`/devices/${device.id}`">გაუქმება</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
