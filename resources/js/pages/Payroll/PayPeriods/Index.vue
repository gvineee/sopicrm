<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import EmptyState from '@/components/states/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';

type PayPeriod = { id: string; starts_on: string; ends_on: string; status: string };

defineOptions({ layout: { mobileTitle: 'ანაზღაურების პერიოდები' } });

defineProps<{ payPeriods: PayPeriod[]; canManage: boolean }>();

const form = useForm({ starts_on: '', ends_on: '' });

function submit() {
    form.post('/payroll/pay-periods', { preserveScroll: true, onSuccess: () => form.reset() });
}

function close(period: PayPeriod) {
    if (!confirm(`დაიხუროს პერიოდი ${period.starts_on} – ${period.ends_on}?`)) return;
    useForm({}).post(`/payroll/pay-periods/${period.id}/close`, { preserveScroll: true });
}
</script>

<template>
    <Head title="ანაზღაურების პერიოდები" />
    <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">ანაზღაურების პერიოდები</h1>
            <p class="text-muted-foreground text-sm">ტაბელები და ანგარიშსწორებები ყოველთვის ერთ პერიოდს ეკუთვნის.</p>
        </div>

        <form v-if="canManage" class="border-border bg-card grid gap-4 rounded-xl border p-5 sm:grid-cols-3" @submit.prevent="submit">
            <div class="grid gap-2">
                <label class="text-sm font-medium">დაწყება</label>
                <Input v-model="form.starts_on" type="date" min="2000-01-01" max="2099-12-31" required />
                <p v-if="form.errors.starts_on" class="text-destructive text-sm">{{ form.errors.starts_on }}</p>
            </div>
            <div class="grid gap-2">
                <label class="text-sm font-medium">დასრულება</label>
                <Input v-model="form.ends_on" type="date" min="2000-01-01" max="2099-12-31" required />
                <p v-if="form.errors.ends_on" class="text-destructive text-sm">{{ form.errors.ends_on }}</p>
            </div>
            <div class="flex items-end">
                <Button type="submit" :disabled="form.processing" class="w-full">პერიოდის დამატება</Button>
            </div>
        </form>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="payPeriods.length === 0" title="პერიოდი არ არის დამატებული" description="დაამატეთ პირველი ანაზღაურების პერიოდი." />
            <div v-else class="divide-border divide-y">
                <div v-for="period in payPeriods" :key="period.id" class="grid gap-3 p-4 md:grid-cols-[1fr_120px_auto] md:items-center">
                    <p class="font-medium">{{ period.starts_on }} – {{ period.ends_on }}</p>
                    <StatusBadge :label="period.status === 'open' ? 'ღია' : 'დახურული'" :tone="period.status === 'open' ? 'success' : 'neutral'" />
                    <button v-if="canManage && period.status === 'open'" type="button" class="text-sm hover:underline" @click="close(period)">დახურვა</button>
                </div>
            </div>
        </div>

        <Link href="/payroll/pay-runs" class="text-sm hover:underline">→ ანგარიშსწორებები</Link>
    </div>
</template>
