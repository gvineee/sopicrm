<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Site = { id: string; name: string };

type ShiftTemplate = {
    id: string;
    site_id: string | null;
    name: string;
    starts_at_local: string;
    ends_at_local: string;
    crosses_midnight: boolean;
    scheduled_days: string[];
    break_policy: { type: 'fixed' | 'scheduled'; minutes?: number; windows?: { key: string; start: string; end: string }[] };
    allowed_late_minutes: number;
    rounding_policy: Record<string, unknown>;
    requires_approval_by_role: string | null;
};

const props = defineProps<{ sites: Site[]; shiftTemplate?: ShiftTemplate }>();

const DAYS: { value: string; label: string }[] = [
    { value: 'mon', label: 'ორშ' },
    { value: 'tue', label: 'სამ' },
    { value: 'wed', label: 'ოთხ' },
    { value: 'thu', label: 'ხუთ' },
    { value: 'fri', label: 'პარ' },
    { value: 'sat', label: 'შაბ' },
    { value: 'sun', label: 'კვ' },
];

const form = useForm({
    site_id: props.shiftTemplate?.site_id ?? '',
    name: props.shiftTemplate?.name ?? '',
    starts_at_local: props.shiftTemplate?.starts_at_local?.slice(0, 5) ?? '09:00',
    ends_at_local: props.shiftTemplate?.ends_at_local?.slice(0, 5) ?? '18:00',
    crosses_midnight: props.shiftTemplate?.crosses_midnight ?? false,
    scheduled_days: props.shiftTemplate?.scheduled_days ?? ['mon', 'tue', 'wed', 'thu', 'fri'],
    break_minutes: props.shiftTemplate?.break_policy?.minutes ?? 60,
    allowed_late_minutes: props.shiftTemplate?.allowed_late_minutes ?? 0,
    requires_approval_by_role: props.shiftTemplate?.requires_approval_by_role ?? '',
});

function submit() {
    const payload = {
        site_id: form.site_id || null,
        name: form.name,
        starts_at_local: form.starts_at_local,
        ends_at_local: form.ends_at_local,
        crosses_midnight: form.crosses_midnight,
        scheduled_days: form.scheduled_days,
        break_policy: { type: 'fixed', minutes: Number(form.break_minutes) },
        allowed_late_minutes: Number(form.allowed_late_minutes),
        rounding_policy: props.shiftTemplate?.rounding_policy ?? { type: 'none' },
        requires_approval_by_role: form.requires_approval_by_role || null,
    };

    const options = { preserveScroll: true };

    if (props.shiftTemplate) {
        form.transform(() => payload).put(`/attendance/shift-templates/${props.shiftTemplate.id}`, options);
        return;
    }

    form.transform(() => payload).post('/attendance/shift-templates', options);
}
</script>

<template>
    <form class="border-border bg-card grid gap-4 rounded-xl border p-5" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="shift-name">დასახელება</Label>
            <Input id="shift-name" v-model="form.name" required maxlength="255" placeholder="მაგ. დღის ცვლა" />
            <p v-if="form.errors.name" class="text-destructive text-sm">{{ form.errors.name }}</p>
        </div>

        <div class="grid gap-2">
            <Label for="shift-site">ობიექტი</Label>
            <select id="shift-site" v-model="form.site_id" class="border-input bg-background rounded-md border px-3 py-2 text-sm">
                <option value="">ყველა ობიექტი</option>
                <option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }}</option>
            </select>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="shift-start">დაწყება</Label>
                <Input id="shift-start" v-model="form.starts_at_local" type="time" required />
                <p v-if="form.errors.starts_at_local" class="text-destructive text-sm">{{ form.errors.starts_at_local }}</p>
            </div>
            <div class="grid gap-2">
                <Label for="shift-end">დასრულება</Label>
                <Input id="shift-end" v-model="form.ends_at_local" type="time" required />
                <p v-if="form.errors.ends_at_local" class="text-destructive text-sm">{{ form.errors.ends_at_local }}</p>
            </div>
        </div>

        <label class="border-border flex cursor-pointer items-center gap-3 rounded-lg border p-3">
            <input v-model="form.crosses_midnight" type="checkbox" class="size-4" />
            <span class="text-sm font-medium">ღამის ცვლა (გადადის შუაღამეს)</span>
        </label>

        <div class="grid gap-2">
            <Label>სამუშაო დღეები</Label>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="day in DAYS"
                    :key="day.value"
                    class="border-border flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm"
                >
                    <input v-model="form.scheduled_days" type="checkbox" :value="day.value" class="size-3.5" />
                    {{ day.label }}
                </label>
            </div>
            <p v-if="form.errors.scheduled_days" class="text-destructive text-sm">{{ form.errors.scheduled_days }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="shift-break">შესვენება (წუთი)</Label>
                <Input id="shift-break" v-model="form.break_minutes" type="number" min="0" />
            </div>
            <div class="grid gap-2">
                <Label for="shift-late">დაშვებული დაგვიანება (წუთი)</Label>
                <Input id="shift-late" v-model="form.allowed_late_minutes" type="number" min="0" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="shift-approver">საჭიროებს დამტკიცებას როლისგან (არასავალდებულო)</Label>
            <Input id="shift-approver" v-model="form.requires_approval_by_role" maxlength="64" placeholder="მაგ. project_manager" />
        </div>

        <Button type="submit" :disabled="form.processing">
            {{ shiftTemplate ? 'ცვლილებების შენახვა' : 'შაბლონის დამატება' }}
        </Button>
    </form>
</template>
