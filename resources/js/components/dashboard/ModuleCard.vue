<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/components/dashboard/StatusBadge.vue';
import { moduleIcon } from '@/lib/moduleIcons';
import type { DashboardCard, DashboardTone } from '@/types';

const props = defineProps<{
    card: DashboardCard;
}>();

let icon = computed(() => moduleIcon(props.card.icon));

const statTone: Record<DashboardTone, string> = {
    success: 'text-foreground',
    warning: 'text-amber-600 dark:text-amber-400',
    danger: 'text-destructive',
    muted: 'text-foreground',
};
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
    >
        <header class="flex items-center justify-between gap-2">
            <div
                class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
            >
                <component :is="icon" class="size-4" />
                {{ card.name }}
            </div>
            <Link
                :href="card.href"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            >
                Open
                <ArrowRight class="size-3.5" />
            </Link>
        </header>

        <div class="flex flex-1 flex-col gap-1.5">
            <StatusBadge
                v-if="card.headline.status"
                :status="card.headline.status"
            />
            <h2 class="text-lg leading-snug font-semibold">
                {{ card.headline.title }}
            </h2>
            <p
                v-if="card.headline.detail"
                class="text-sm text-muted-foreground"
            >
                {{ card.headline.detail }}
            </p>
        </div>

        <dl class="grid grid-cols-3 gap-2 border-t pt-4">
            <div v-for="stat in card.stats" :key="stat.label" class="min-w-0">
                <dt class="text-xs leading-tight text-muted-foreground">
                    {{ stat.label }}
                </dt>
                <dd
                    class="text-lg font-semibold tabular-nums"
                    :class="statTone[stat.tone ?? 'muted']"
                >
                    {{ stat.value }}
                </dd>
            </div>
        </dl>
    </section>
</template>
