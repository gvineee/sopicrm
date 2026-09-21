<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmptyState from '@/components/states/EmptyState.vue';

type EmployeeSummary = {
    id: string;
    full_name: string;
    internal_code: string;
    position_name?: string | null;
    team_name?: string | null;
    supervisor_name?: string | null;
    status: string;
};

type AccessEvent = {
    id: string;
    device_name?: string | null;
    direction?: string | null;
    event_code: string;
    occurred_at: string;
};

const props = defineProps<{
    employee: EmployeeSummary | null;
    recentAccessEvents: AccessEvent[];
}>();

defineOptions({ layout: { mobileTitle: 'ჩემი პროფილი' } });

function directionLabel(event: AccessEvent): string {
    if (event.direction === 'in' || event.direction === 'entry') return 'შემოსვლა';
    if (event.direction === 'out' || event.direction === 'exit') return 'გასვლა';
    return event.event_code;
}
</script>

<template>
    <Head title="ჩემი პროფილი" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold">ჩემი პროფილი</h1>
            <p class="text-muted-foreground text-sm">თქვენი პირადი სამუშაო ინფორმაცია.</p>
        </div>

        <EmptyState
            v-if="!employee"
            title="თანამშრომლის ჩანაწერთან დაკავშირება არ არის"
            description="თქვენი ანგარიშისთვის თანამშრომლის პროფილი ჯერ არ არის მიბმული. მიმართეთ HR-ს."
        />

        <template v-else>
            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">ძირითადი ინფორმაცია</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted-foreground">სახელი</dt><dd>{{ employee.full_name }}</dd></div>
                    <div><dt class="text-muted-foreground">შიდა კოდი</dt><dd>{{ employee.internal_code }}</dd></div>
                    <div><dt class="text-muted-foreground">პოზიცია</dt><dd>{{ employee.position_name || '—' }}</dd></div>
                    <div><dt class="text-muted-foreground">ბრიგადა</dt><dd>{{ employee.team_name || '—' }}</dd></div>
                    <div><dt class="text-muted-foreground">ხელმძღვანელი</dt><dd>{{ employee.supervisor_name || '—' }}</dd></div>
                </dl>
            </section>

            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">ნამუშევარი საათები და ხელფასი</h2>
                <EmptyState
                    class="mt-3"
                    title="ჯერ ხელმისაწვდომი არ არის"
                    description="დასწრებისა და ანაზღაურების გამოთვლის მოდულები ჯერ დამატებული არ არის — ეს მონაცემები აქ გამოჩნდება მათი დამატების შემდეგ."
                />
            </section>

            <section class="border-border bg-card rounded-xl border p-5">
                <h2 class="font-semibold">მოსვლა-წასვლის ბოლო ჩანაწერები</h2>
                <p class="text-muted-foreground mt-1 text-xs">
                    ნედლი მონაცემი მოწყობილობებიდან — არ არის დამუშავებული სამუშაო საათების სახით.
                </p>
                <EmptyState v-if="!recentAccessEvents.length" class="mt-3" title="ჩანაწერი ჯერ არ არის" />
                <div v-else class="mt-3 space-y-2 text-sm">
                    <div v-for="event in recentAccessEvents" :key="event.id" class="flex items-center justify-between rounded-lg border p-3">
                        <span>{{ directionLabel(event) }} · {{ event.device_name || 'უცნობი მოწყობილობა' }}</span>
                        <span class="text-muted-foreground">{{ new Date(event.occurred_at).toLocaleString('ka-GE') }}</span>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
