<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Policy = {
    full_day_threshold_minutes: number;
    half_day_threshold_minutes: number;
    minimum_attendance_minutes: number;
    incomplete_day_behavior: string;
    max_day_units_per_work_date: string;
    notes: string | null;
    is_confirmed: boolean;
} | null;

defineOptions({ layout: { mobileTitle: 'დღიური ანაზღაურების პოლიტიკა' } });

const props = defineProps<{ policy: Policy; canManage: boolean }>();

const form = useForm({
    full_day_threshold_minutes: props.policy?.full_day_threshold_minutes ?? 480,
    half_day_threshold_minutes: props.policy?.half_day_threshold_minutes ?? 240,
    minimum_attendance_minutes: props.policy?.minimum_attendance_minutes ?? 60,
    incomplete_day_behavior: props.policy?.incomplete_day_behavior ?? 'half_day',
    max_day_units_per_work_date: props.policy?.max_day_units_per_work_date ?? '1.00',
    notes: props.policy?.notes ?? '',
});

function submit() {
    form.put('/payroll/daily-pay-policy', { preserveScroll: true });
}
</script>

<template>
    <Head title="დღიური ანაზღაურების პოლიტიკა" />
    <div class="mx-auto flex w-full max-w-xl flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">დღიური ანაზღაურების პოლიტიკა</h1>
            <p class="text-muted-foreground text-sm">
                სანამ ეს არ დადასტურდება ბუღალტრის მიერ, დღიური ტარიფის ანგარიშსწორება დაბლოკილია.
                <span v-if="policy?.is_confirmed" class="text-success font-medium">ამჟამად დადასტურებულია.</span>
                <span v-else class="text-warning font-medium">ჯერ არ არის დადასტურებული.</span>
            </p>
        </div>

        <form v-if="canManage" class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label>სრული დღის ზღვარი (წუთი)</Label>
                <Input v-model="form.full_day_threshold_minutes" type="number" min="1" required />
            </div>
            <div class="grid gap-2">
                <Label>ნახევარი დღის ზღვარი (წუთი)</Label>
                <Input v-model="form.half_day_threshold_minutes" type="number" min="1" required />
            </div>
            <div class="grid gap-2">
                <Label>მინიმალური დასწრება (წუთი)</Label>
                <Input v-model="form.minimum_attendance_minutes" type="number" min="0" required />
            </div>
            <div class="grid gap-2">
                <Label>არასრული დღის ქცევა</Label>
                <Input v-model="form.incomplete_day_behavior" required placeholder="მაგ. half_day" />
            </div>
            <div class="grid gap-2">
                <Label>მაქს. დღის ერთეული ერთ თარიღზე</Label>
                <Input v-model="form.max_day_units_per_work_date" type="number" step="0.01" min="0.01" required />
            </div>
            <div class="grid gap-2">
                <Label>შენიშვნები</Label>
                <textarea v-model="form.notes" rows="2" class="border-input bg-background rounded-md border px-3 py-2 text-sm"></textarea>
            </div>
            <Button type="submit" :disabled="form.processing">დადასტურება</Button>
        </form>
    </div>
</template>
