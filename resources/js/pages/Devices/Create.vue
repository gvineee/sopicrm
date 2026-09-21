<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({ layout: { mobileTitle: 'მოწყობილობის დამატება' } });

defineProps<{
    sites: Array<{ id: string; name: string }>;
}>();

const form = useForm({
    name: '',
    vendor: 'suprema',
    site_id: '',
    serial_number: '',
    device_identifier: '',
    ip_address: '',
    port: '',
    mac_address: '',
    model: 'XP2-MDPB',
    firmware_version: '',
    hardware_version: '',
    connection_mode: 'gateway',
    install_location: '',
    reader_role: 'unspecified',
    device_timezone: 'Asia/Tbilisi',
    timezone: 'Asia/Tbilisi',
    enabled: true,
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
        }))
        .post('/devices');
}
</script>

<template>
    <Head title="მოწყობილობის დამატება" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <Link href="/devices" class="text-muted-foreground text-sm hover:underline"
                >← მოწყობილობები</Link
            >
            <h1 class="mt-2 text-2xl font-semibold">მოწყობილობის დამატება</h1>
            <p class="text-muted-foreground text-sm">
                რეალურ hardware-თან დაკავშირება ხდება ცალკე adapter-ის მეშვეობით — ეს
                მხოლოდ ODA-ს device registry-ს ჩანაწერს ქმნის.
            </p>
        </div>

        <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2"><Label>სახელი</Label><Input v-model="form.name" required placeholder="მთავარი შესასვლელი" /></div>
                <div class="grid gap-2"><Label>მწარმოებელი</Label><Input v-model="form.vendor" required /></div>
            </div>
            <div class="grid gap-2">
                <Label>ობიექტი</Label>
                <select
                    v-model="form.site_id"
                    required
                    class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                >
                    <option value="" disabled>აირჩიეთ ობიექტი</option>
                    <option v-for="site in sites" :key="site.id" :value="site.id">
                        {{ site.name }}
                    </option>
                </select>
                <p v-if="form.errors.site_id" class="text-destructive text-sm">{{ form.errors.site_id }}</p>
            </div>

            <div class="grid gap-2">
                <Label>სერიული ნომერი / Device ID</Label>
                <Input v-model="form.serial_number" required placeholder="მაგ. XP2-41087" />
                <p v-if="form.errors.serial_number" class="text-destructive text-sm">{{ form.errors.serial_number }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2"><Label>მოწყობილობის იდენტიფიკატორი</Label><Input v-model="form.device_identifier" placeholder="gateway-device-id" /></div>
                <div class="grid gap-2"><Label>Hardware ვერსია</Label><Input v-model="form.hardware_version" /></div>
            </div>

            <div class="grid gap-2 sm:grid-cols-2 sm:gap-4">
                <div class="grid gap-2">
                    <Label>მოდელი</Label>
                    <Input v-model="form.model" required />
                </div>
                <div class="grid gap-2">
                    <Label>Firmware ვერსია</Label>
                    <Input v-model="form.firmware_version" placeholder="დასადასტურებელია პილოტზე" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label>მდებარეობა / კარი</Label>
                <Input v-model="form.install_location" placeholder="მაგ. მთავარი შესასვლელი" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2"><Label>IP მისამართი</Label><Input v-model="form.ip_address" placeholder="192.168.10.201" /></div>
                <div class="grid gap-2"><Label>პორტი</Label><Input v-model="form.port" type="number" min="1" max="65535" placeholder="51211" /></div>
                <div class="grid gap-2"><Label>MAC მისამართი</Label><Input v-model="form.mac_address" placeholder="AA:BB:CC:DD:EE:FF" /></div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2"><Label>კავშირის რეჟიმი</Label><select v-model="form.connection_mode" class="border-input bg-background h-9 rounded-md border px-3 text-sm"><option value="gateway">Gateway</option><option value="tcp">TCP</option><option value="udp">UDP</option><option value="other">სხვა</option></select></div>
                <label class="flex items-center gap-2 pt-7 text-sm"><input v-model="form.enabled" type="checkbox" /> აქტიურია</label>
            </div>

            <div class="grid gap-2 sm:grid-cols-2 sm:gap-4">
                <div class="grid gap-2">
                    <Label>Reader-ის როლი</Label>
                    <select
                        v-model="form.reader_role"
                        class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="in">მხოლოდ შესვლა (IN)</option>
                        <option value="out">მხოლოდ გასვლა (OUT)</option>
                        <option value="unspecified">
                            ერთი reader — IN/OUT (დღის პირველი/ბოლო წაკითხვა)
                        </option>
                    </select>
                    <p class="text-muted-foreground text-xs">
                        „unspecified" ნიშნავს, რომ ეს ერთი reader ორივე მიმართულებას
                        ემსახურება — მიმართულება არასდროს გამოითვლება ყოველი მეორე
                        წაკითხვის მონაცვლეობით.
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label>დროის სარტყელი</Label>
                    <Input v-model="form.device_timezone" />
                </div>
            </div>

            <Button type="submit" :disabled="form.processing">დამატება</Button>
        </form>
    </div>
</template>
