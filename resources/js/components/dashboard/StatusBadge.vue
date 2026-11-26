<script setup lang="ts">
import type { DashboardStatus, DashboardTone } from '@/types';

defineProps<{
    status: DashboardStatus;
}>();

const tones: Record<DashboardTone, string> = {
    success:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    warning:
        'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    danger: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
    muted: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <span
        class="inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="tones[status.tone]"
    >
        {{ status.label }}
    </span>
</template>
