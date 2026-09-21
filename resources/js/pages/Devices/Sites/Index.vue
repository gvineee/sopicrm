<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/states/EmptyState.vue';

type Site = {
    id: string;
    name: string;
    address: string | null;
    is_active: boolean;
    company_id: string | null;
    company_name: string | null;
    device_count: number;
};

const props = defineProps<{
    sites: Site[];
    companies: Array<{ id: string; name: string }>;
}>();

defineOptions({ layout: { mobileTitle: 'საიტები' } });

const openAssign = reactive<Record<string, boolean>>({});
const assignForms = reactive<Record<string, ReturnType<typeof useForm<{ company_id: string }>>>>({});

function assignFormFor(siteId: string) {
    if (!assignForms[siteId]) {
        assignForms[siteId] = useForm({ company_id: '' });
    }
    return assignForms[siteId];
}

function submitAssign(siteId: string) {
    assignFormFor(siteId).post(`/sites/${siteId}/assign-company`, {
        preserveScroll: true,
        onSuccess: () => {
            openAssign[siteId] = false;
        },
    });
}

const unmappedCount = props.sites.filter((site) => site.company_id === null).length;
</script>

<template>
    <Head title="საიტები" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">საიტები</h1>
            <p class="text-muted-foreground text-sm">
                თითოეული საიტი შეიძლება დაუკავშირდეს კონკრეტულ კომპანიას — დაუკავშირებელი საიტის მოწყობილობები/თანამშრომლები
                ხილვადია ორგანიზაციის ნებისმიერი უფლებამოსილი წევრისთვის, სანამ კომპანია არ მიენიჭება.
            </p>
            <p v-if="unmappedCount > 0" class="text-warning-foreground mt-1 text-sm font-medium">
                {{ unmappedCount }} საიტს ჯერ არ აქვს მინიჭებული კომპანია.
            </p>
        </div>

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <EmptyState v-if="sites.length === 0" title="საიტი არ არის" description="ჯერ არცერთი საიტი არ არსებობს." />
            <div v-else class="divide-border divide-y">
                <div v-for="site in sites" :key="site.id" class="p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ site.name }}</p>
                            <p class="text-muted-foreground truncate text-sm">
                                {{ site.address || 'მისამართი მითითებული არ არის' }} · {{ site.device_count }} მოწყობილობა
                            </p>
                        </div>
                        <StatusBadge
                            :label="site.company_name || 'დაუკავშირებელი'"
                            :tone="site.company_name ? 'success' : 'warning'"
                        />
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" @click="openAssign[site.id] = !openAssign[site.id]">
                            {{ site.company_id ? 'კომპანიის შეცვლა' : 'კომპანიის მინიჭება' }}
                        </Button>
                    </div>

                    <form
                        v-if="openAssign[site.id]"
                        class="border-border mt-3 grid gap-2 rounded-lg border p-3 md:grid-cols-3"
                        @submit.prevent="submitAssign(site.id)"
                    >
                        <select
                            v-model="assignFormFor(site.id).company_id"
                            required
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="" disabled>აირჩიეთ კომპანია</option>
                            <option v-for="company in companies" :key="company.id" :value="company.id">
                                {{ company.name }}
                            </option>
                        </select>
                        <Button type="submit" size="sm" class="w-fit" :disabled="assignFormFor(site.id).processing">
                            შენახვა
                        </Button>
                        <p v-if="assignFormFor(site.id).errors.company_id" class="text-destructive text-sm md:col-span-3">
                            {{ assignFormFor(site.id).errors.company_id }}
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
