<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, CircleCheck, OctagonAlert } from '@lucide/vue';
import { computed } from 'vue';
import ModuleCard from '@/components/dashboard/ModuleCard.vue';
import StatusBadge from '@/components/dashboard/StatusBadge.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { formatDateTime } from '@/lib/format';
import { moduleIcon } from '@/lib/moduleIcons';
import { dashboard } from '@/routes';
import type { DashboardCard, DashboardInvitation, Team } from '@/types';

const props = withDefaults(
    defineProps<{
        pendingInvitations?: DashboardInvitation[];
        cards?: DashboardCard[];
    }>(),
    { pendingInvitations: () => [], cards: () => [] },
);

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

/** Everything that needs someone to act, most serious first. */
let attention = computed(() =>
    props.cards
        .flatMap((card) =>
            card.attention.map((item) => ({ ...item, icon: card.icon })),
        )
        .sort(
            (a, b) => Number(b.tone === 'danger') - Number(a.tone === 'danger'),
        ),
);

/** The latest happenings across every module, newest first. */
let activity = computed(() =>
    props.cards
        .flatMap((card) =>
            card.activity.map((item) => ({ ...item, icon: card.icon })),
        )
        .sort((a, b) => Date.parse(b.at) - Date.parse(a.at))
        .slice(0, 10),
);

let upcoming = computed(() => props.cards.find((card) => card.upcoming));

let gridColumns = computed(
    () =>
        ({
            1: 'md:grid-cols-1',
            2: 'md:grid-cols-2',
        })[props.cards.length] ?? 'md:grid-cols-2 xl:grid-cols-3',
);
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <p
            v-if="cards.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Nothing to show yet. Once a super admin turns on a module for this
            congregation and gives you access, its summary appears here.
        </p>

        <template v-else>
            <div class="grid auto-rows-fr gap-4" :class="gridColumns">
                <ModuleCard
                    v-for="card in cards"
                    :key="card.key"
                    :card="card"
                />
            </div>

            <section
                class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <h2 class="border-b px-5 py-3 text-sm font-semibold">
                    Needs attention
                </h2>
                <p
                    v-if="attention.length === 0"
                    class="flex items-center gap-2 px-5 py-4 text-sm text-muted-foreground"
                >
                    <CircleCheck class="size-4 text-emerald-600" />
                    All clear. Nothing needs attention right now.
                </p>
                <ul v-else class="divide-y">
                    <li v-for="(item, index) in attention" :key="index">
                        <Link
                            :href="item.href"
                            class="flex items-start gap-3 px-5 py-3 hover:bg-muted/40"
                        >
                            <OctagonAlert
                                v-if="item.tone === 'danger'"
                                class="mt-0.5 size-4 shrink-0 text-destructive"
                            />
                            <AlertTriangle
                                v-else
                                class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
                            />
                            <span class="min-w-0">
                                <span class="block text-sm font-medium">
                                    {{ item.title }}
                                </span>
                                <span
                                    v-if="item.detail"
                                    class="block text-sm text-muted-foreground"
                                >
                                    {{ item.detail }}
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>

            <div
                class="grid gap-4"
                :class="{ 'lg:grid-cols-2': upcoming?.upcoming }"
            >
                <section
                    v-if="upcoming?.upcoming"
                    class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <h2 class="border-b px-5 py-3 text-sm font-semibold">
                        {{ upcoming.upcoming.title }}
                    </h2>
                    <p
                        v-if="upcoming.upcoming.items.length === 0"
                        class="px-5 py-4 text-sm text-muted-foreground"
                    >
                        No services are planned yet.
                        <Link
                            :href="upcoming.href"
                            class="underline underline-offset-4"
                        >
                            Add one
                        </Link>
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="item in upcoming.upcoming.items"
                            :key="item.href"
                        >
                            <Link
                                :href="item.href"
                                class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-muted/40"
                            >
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-sm font-medium"
                                    >
                                        {{ item.title }}
                                    </span>
                                    <span
                                        v-if="item.detail"
                                        class="block text-sm text-muted-foreground"
                                    >
                                        {{ item.detail }}
                                    </span>
                                </span>
                                <StatusBadge
                                    v-if="item.status"
                                    :status="item.status"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>

                <section
                    class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <h2 class="border-b px-5 py-3 text-sm font-semibold">
                        Recent activity
                    </h2>
                    <p
                        v-if="activity.length === 0"
                        class="px-5 py-4 text-sm text-muted-foreground"
                    >
                        No activity yet.
                    </p>
                    <ul v-else class="divide-y">
                        <li v-for="(item, index) in activity" :key="index">
                            <Link
                                :href="item.href"
                                class="flex items-start gap-3 px-5 py-3 hover:bg-muted/40"
                            >
                                <component
                                    :is="moduleIcon(item.icon)"
                                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium">
                                        {{ item.title }}
                                    </span>
                                    <span
                                        v-if="item.detail"
                                        class="block truncate text-sm text-muted-foreground"
                                    >
                                        {{ item.detail }}
                                    </span>
                                </span>
                                <time
                                    :datetime="item.at"
                                    class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    {{ formatDateTime(item.at) }}
                                </time>
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </template>
    </div>
</template>
