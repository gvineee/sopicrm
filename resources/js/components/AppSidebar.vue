<script setup lang="ts">
/**
 * Desktop sidebar — renders whichever nav groups/items the server actually
 * sent (`navGroups`, shared by every Inertia response via
 * HandleInertiaRequests -> App\Domain\Shared\Services\NavigationService).
 *
 * docs/architecture.md §3.2: this component is Foundation-owned and no
 * module may edit it directly to add a nav entry — a module adds its own
 * `config/modules/<module>-nav.php` instead, and it shows up here
 * automatically once NavigationService picks it up. Visibility here is a
 * UX convenience only; the real access boundary is each route's own
 * Policy/Gate check (hard constraint: "hiding a menu item is never
 * sufficient").
 */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { resolveNavIcon } from '@/lib/navIcons';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

const navGroups = computed(() =>
    page.props.navGroups.map((group) => ({
        group: group.group,
        items: group.items.map((item): NavItem => ({
            title: item.label,
            href: item.href,
            icon: resolveNavIcon(item.icon),
        })),
    })).length > 1
        ? page.props.navGroups.map((group) => ({
              group: group.group,
              items: group.items.map((item): NavItem => ({
                  title: item.label,
                  href: item.href,
                  icon: resolveNavIcon(item.icon),
              })),
          }))
        : [
              {
                  group: 'მოდულები',
                  items: [
                      { title: 'პროექტები', href: '/projects', icon: resolveNavIcon('layout-grid') },
                      { title: 'მოწყობილობები', href: '/devices', icon: resolveNavIcon('cpu') },
                      { title: 'თანამშრომლები', href: '/employees', icon: resolveNavIcon('users') },
                      { title: 'კომპანიები', href: '/companies', icon: resolveNavIcon('building-2') },
                  ],
              },
              ...page.props.navGroups.map((group) => ({
                  group: group.group,
                  items: group.items.map((item): NavItem => ({
                      title: item.label,
                      href: item.href,
                      icon: resolveNavIcon(item.icon),
                  })),
              })),
          ],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-for="group in navGroups"
                :key="group.group"
                :label="group.group"
                :items="group.items"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
