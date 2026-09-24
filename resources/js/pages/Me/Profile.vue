<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '@/components/states/EmptyState.vue';
import { Button } from '@/components/ui/button';

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
    is_simulated?: boolean;
};

const props = withDefaults(
    defineProps<{
        employee: EmployeeSummary | null;
        recentAccessEvents: AccessEvent[];
        canLinkAccounts?: boolean;
    }>(),
    { canLinkAccounts: false },
);

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

        <!-- Audit A11: this used to end at "მიმართეთ HR-ს", which is a dead
             end — especially for an administrator, who is exactly the person
             able to fix it. Now it names the next step for whoever is
             reading, and always offers somewhere to go. -->
        <div v-if="!employee" class="flex flex-col gap-3">
            <EmptyState
                title="თანამშრომლის ჩანაწერთან დაკავშირება არ არის"
                :description="
                    canLinkAccounts
                        ? 'თქვენი ანგარიში ჯერ არ არის მიბმული თანამშრომლის ჩანაწერზე. იპოვეთ თქვენი ჩანაწერი თანამშრომლების სიაში და გამოიყენეთ „არსებული ანგარიშის დაკავშირება“.'
                        : 'თქვენი ანგარიში ჯერ არ არის მიბმული თანამშრომლის ჩანაწერზე. მიმართეთ HR-ს — მიბმის გარეშე ამ გვერდზე პირადი სამუშაო ინფორმაცია არ გამოჩნდება.'
                "
            />
            <div class="flex flex-wrap justify-center gap-2">
                <Link v-if="canLinkAccounts" href="/employees">
                    <Button variant="outline">თანამშრომლების სია</Button>
                </Link>
                <Link href="/dashboard">
                    <Button variant="ghost">მთავარ გვერდზე</Button>
                </Link>
            </div>
        </div>

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
                        <span class="flex items-center gap-2">
                            <span>{{ directionLabel(event) }} · {{ event.device_name || 'უცნობი მოწყობილობა' }}</span>
                            <!-- Audit A04: a generated swipe shown next to a
                                 real one, with nothing to tell them apart, is
                                 how a person concludes the system recorded an
                                 arrival that never happened. -->
                            <span
                                v-if="event.is_simulated"
                                class="border-warning/40 bg-warning/10 text-warning-foreground rounded-full border px-2 py-0.5 text-[11px]"
                                >სატესტო — ნამუშევარ დროში არ ითვლება</span
                            >
                        </span>
                        <span class="text-muted-foreground">{{ new Date(event.occurred_at).toLocaleString('ka-GE') }}</span>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
