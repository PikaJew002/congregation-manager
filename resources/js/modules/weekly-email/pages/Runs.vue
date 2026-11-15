<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { runs as runsRoute } from '@/routes/weekly-email';
import { show } from '@/routes/weekly-email/runs';
import type { Team } from '@/types';
import { formatDateTime, statusVariant } from '../format';
import type { EmailRun } from '../types';

defineProps<{
    runs: EmailRun[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Email runs',
                href: runsRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
</script>

<template>
    <Head title="Email runs" />

    <div class="flex flex-col space-y-6 p-4">
        <Heading
            title="Email runs"
            description="The 50 most recent weekly sends"
        />

        <p
            v-if="runs.length === 0"
            class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            Nothing has been sent yet. Runs appear here once the schedule is on
            and the send time arrives.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Scheduled for</th>
                        <th class="px-4 py-2 font-medium">Subject</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 text-right font-medium">Sent</th>
                        <th class="px-4 py-2 text-right font-medium">Failed</th>
                        <th class="px-4 py-2 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="run in runs"
                        :key="run.id"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-2 whitespace-nowrap">
                            <Link
                                :href="show([teamSlug, run.id])"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ formatDateTime(run.scheduled_for) }}
                            </Link>
                        </td>
                        <td class="px-4 py-2">{{ run.subject }}</td>
                        <td class="px-4 py-2">
                            <Badge :variant="statusVariant(run.status)">
                                {{ run.status }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ run.sent_count ?? 0 }}
                        </td>
                        <td
                            class="px-4 py-2 text-right tabular-nums"
                            :class="{ 'text-destructive': run.failed_count }"
                        >
                            {{ run.failed_count ?? 0 }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ run.deliveries_count ?? 0 }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
