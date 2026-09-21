<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { api } from '@/lib/api';
import { onMounted, ref } from 'vue';

const props = defineProps<{
    mutedTypes: string[];
    availableTypes: Record<string, string>;
}>();

defineOptions({ layout: { mobileTitle: 'შეტყობინებები' } });

const muted = ref<Set<string>>(new Set(props.mutedTypes));
const saving = ref(false);

function toggle(type: string) {
    if (muted.value.has(type)) {
        muted.value.delete(type);
    } else {
        muted.value.add(type);
    }
    muted.value = new Set(muted.value);
}

async function save() {
    saving.value = true;
    try {
        await api.put('/notifications/preferences', { muted_types: Array.from(muted.value) });
    } finally {
        saving.value = false;
    }
}

type TelegramStatus = {
    linked: boolean;
    pending_code: string | null;
    report_types: string[];
    deliveries: { id: string; report_type: string; status: string; failed_reason: string | null; sent_at: string | null; created_at: string | null }[];
};

const telegram = ref<TelegramStatus | null>(null);
const isLocalEnv = ref(false);

async function loadTelegram() {
    telegram.value = await api.get<TelegramStatus>('/notifications/telegram');
}

async function generateCode() {
    await api.post('/notifications/telegram/link');
    await loadTelegram();
}

async function completeDemoLink() {
    isLocalEnv.value = true;
    try {
        await api.post('/notifications/telegram/link/complete-demo');
        await loadTelegram();
    } catch {
        isLocalEnv.value = false;
    }
}

async function requestReport(type: string) {
    await api.post('/notifications/telegram/reports', { report_type: type });
    await loadTelegram();
}

async function retry(deliveryId: string) {
    await api.post(`/notifications/telegram/deliveries/${deliveryId}/retry`);
    await loadTelegram();
}

onMounted(loadTelegram);

const reportLabels: Record<string, string> = {
    attendance_summary: 'დასწრება',
    exceptions: 'გამონაკლისები',
    overdue_work: 'ვადაგადაცილებული სამუშაო',
    pending_acceptance: 'მისაღები დავალებები',
    biostar_import_lag: 'BioStar იმპორტის ჩამორჩენა',
    financial_summary: 'ფინანსური შეჯამება',
};

const statusLabels: Record<string, string> = {
    queued: 'რიგში',
    sent: 'გაგზავნილი',
    failed: 'ვერ გაიგზავნა',
};
</script>

<template>
    <Head title="შეტყობინებების პარამეტრები" />

    <div class="space-y-8 p-4 md:p-6">
        <Heading
            variant="small"
            title="შეტყობინებების პარამეტრები"
            description="აირჩიეთ, რომელი ტიპის შეტყობინებები გსურთ დამალვა"
        />

        <div class="space-y-3">
            <label
                v-for="(label, type) in availableTypes"
                :key="type"
                class="flex items-center gap-2 text-sm"
            >
                <Checkbox
                    :model-value="!muted.has(type)"
                    @update:model-value="() => toggle(type)"
                />
                {{ label }}
            </label>
            <Button :disabled="saving" @click="save">შენახვა</Button>
        </div>

        <div class="border-border space-y-4 border-t pt-6">
            <Heading
                variant="small"
                title="Telegram — მფლობელის რეპორტები"
                description="მხოლოდ წაკითხვადი რეპორტები (read-only). ამ გარემოში ბოტთან რეალური კავშირი არ არსებობს — მხოლოდ დემონსტრაციული."
            />

            <div v-if="telegram">
                <p v-if="telegram.linked" class="text-sm">დაკავშირებულია ✅</p>
                <div v-else class="space-y-2">
                    <p class="text-muted-foreground text-sm">
                        Telegram ჯერ არ არის დაკავშირებული.
                    </p>
                    <Button v-if="!telegram.pending_code" @click="generateCode">კოდის გენერირება</Button>
                    <div v-else class="space-y-2">
                        <p class="text-sm">
                            კოდი: <span class="font-mono font-semibold">{{ telegram.pending_code }}</span>
                        </p>
                        <Button variant="secondary" @click="completeDemoLink">
                            დაკავშირების სიმულაცია (ტესტირებისთვის)
                        </Button>
                    </div>
                </div>

                <div v-if="telegram.linked" class="mt-4 space-y-2">
                    <p class="text-sm font-medium">რეპორტის მოთხოვნა</p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="type in telegram.report_types"
                            :key="type"
                            size="sm"
                            variant="outline"
                            @click="requestReport(type)"
                        >
                            {{ reportLabels[type] ?? type }}
                        </Button>
                    </div>
                </div>

                <div v-if="telegram.deliveries.length > 0" class="mt-4 space-y-2">
                    <p class="text-sm font-medium">გაგზავნების ისტორია</p>
                    <div
                        v-for="delivery in telegram.deliveries"
                        :key="delivery.id"
                        class="flex items-center justify-between text-sm"
                    >
                        <span>{{ reportLabels[delivery.report_type] ?? delivery.report_type }} — {{ statusLabels[delivery.status] ?? delivery.status }}</span>
                        <Button v-if="delivery.status === 'failed'" size="sm" variant="ghost" @click="retry(delivery.id)">
                            ხელახლა გაგზავნა
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
