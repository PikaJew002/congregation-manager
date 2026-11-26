<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { moduleIcon } from '@/lib/moduleIcons';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

let dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

let mainNavItems = computed<NavItem[]>(() => {
    let items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboardUrl.value,
            icon: LayoutGrid,
        },
    ];

    const modules = (page.props.modules ?? []) as Array<{
        name: string;
        icon: string;
        items: Array<{ title: string; href: string }>;
    }>;

    for (const module of modules) {
        if (module.items.length === 0) {
            continue;
        }

        items.push({
            title: module.name,
            href: module.items[0].href,
            icon: moduleIcon(module.icon),
            items: module.items.map((item) => ({
                title: item.title,
                href: item.href,
            })),
        });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem>
                    <TeamSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
