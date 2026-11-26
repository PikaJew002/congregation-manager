<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { runs as runsRoute } from '@/routes/weekly-email';
import { edit as editTeam } from '@/routes/teams';
import { cancel, resume, retry, show } from '@/routes/weekly-email/runs';
import type { Team } from '@/types';
import { formatDateTime, statusVariant } from '../format';
import type { EmailDelivery, EmailRun } from '../types';

const props = defineProps<{
    run: EmailRun;
    deliveries: EmailDelivery[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null; run: EmailRun }) => ({
        breadcrumbs: [
            {
                title: 'Email runs',
                href: runsRoute(props.currentTeam?.slug ?? ''),
            },
            {
                title: props.run.subject,
                href: show([props.currentTeam?.slug ?? '', props.run.id]),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

let counts = computed(() => {
    const result = { pending: 0, sent: 0, bounced: 0, failed: 0, cancelled: 0 };

    for (const delivery of props.deliveries) {
        result[delivery.status]++;
    }

    return result;
});

let canCancel = computed(
    () =>
        ['scheduled', 'sending', 'paused'].includes(props.run.status) &&
        counts.value.pending > 0,
);

// Only people still on the list are retried; a bounced or suppressed
// address would fail again.
let retryable = computed(
    () =>
        props.deliveries.filter(
            (delivery) =>
                delivery.status === 'failed' &&
                delivery.recipient?.status === 'subscribed',
        ).length,
);

let canRetry = computed(
    () =>
        !['cancelled', 'paused'].includes(props.run.status) &&
        retryable.value > 0,
);

let cancelling = ref(false);

const cancelRun = () => {
    if (
        !confirm(
            `Cancel this run? ${counts.value.pending} pending emails will not be sent.`,
        )
    ) {
        return;
    }

    router.visit(cancel([teamSlug.value, props.run.id]), {
        preserveScroll: true,
        onStart: () => (cancelling.value = true),
        onFinish: () => (cancelling.value = false),
    });
};
</script>

<template>
    <Head :title="run.subject" />

    <div class="flex flex-col space-y-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading
                    :title="run.subject"
                    :description="`Scheduled for ${formatDateTime(run.scheduled_for)}`"
                />
                <Badge :variant="statusVariant(run.status)">
                    {{ run.status }}
                </Badge>
            </div>

            <div class="flex gap-2">
                <Form
                    v-if="run.status === 'paused'"
                    v-bind="resume.form([teamSlug, run.id])"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <Button type="submit" :disabled="processing">
                        Resume sending
                    </Button>
                </Form>
                <Form
                    v-if="canRetry"
                    v-bind="retry.form([teamSlug, run.id])"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="secondary"
                        :disabled="processing"
                    >
                        Retry {{ retryable }} failed
                    </Button>
                </Form>
                <Button
                    v-if="canCancel"
                    variant="destructive"
                    :disabled="cancelling"
                    @click="cancelRun"
                >
                    Cancel run
                </Button>
            </div>
        </div>

        <div
            v-if="run.status === 'paused'"
            class="space-y-1 rounded-lg border border-red-100 bg-red-50 p-4 text-sm text-red-700 dark:border-red-200/10 dark:bg-red-700/10 dark:text-red-100"
        >
            <p class="font-medium">
                Sending paused {{ formatDateTime(run.paused_at) }}
            </p>
            <p>{{ run.pause_reason }}</p>
            <p>
                Fix it in the
                <Link
                    v-if="teamSlug"
                    :href="editTeam(teamSlug)"
                    class="underline underline-offset-4"
                    >congregation settings</Link
                >, then resume. The {{ counts.pending }} emails not yet sent go
                out from then at the usual pace. A run still paused a day after
                its send time is cancelled.
            </p>
        </div>

        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div
                v-for="(count, status) in counts"
                :key="status"
                class="rounded-lg border p-4"
            >
                <dt class="text-sm text-muted-foreground capitalize">
                    {{ status }}
                </dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ count }}</dd>
            </div>
        </dl>

        <details class="rounded-lg border p-4">
            <summary class="cursor-pointer text-sm font-medium">
                Message body (as sent)
            </summary>
            <pre
                class="mt-4 font-mono text-sm whitespace-pre-wrap text-muted-foreground"
                >{{ run.body }}</pre>
        </details>

        <div class="space-y-4">
            <Heading variant="small" title="Deliveries" />

            <p
                v-if="deliveries.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                This run had no subscribed recipients.
            </p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Recipient</th>
                            <th class="px-4 py-2 font-medium">Scheduled</th>
                            <th class="px-4 py-2 font-medium">Sent</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="delivery in deliveries"
                            :key="delivery.id"
                            class="border-t align-top"
                        >
                            <td class="px-4 py-2">
                                <template v-if="delivery.recipient">
                                    <div class="font-medium">
                                        {{ delivery.recipient.email }}
                                    </div>
                                    <div
                                        v-if="delivery.recipient.name"
                                        class="text-muted-foreground"
                                    >
                                        {{ delivery.recipient.name }}
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground">
                                    Removed recipient
                                </span>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ formatDateTime(delivery.scheduled_at) }}
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ formatDateTime(delivery.sent_at) }}
                            </td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="statusVariant(delivery.status)"
                                >
                                    {{ delivery.status }}
                                </Badge>
                                <p
                                    v-if="delivery.error"
                                    class="mt-1 max-w-md text-xs break-words text-destructive"
                                >
                                    {{ delivery.error }}
                                </p>
                                <p
                                    v-if="delivery.last_event === 'complained'"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    Reported as spam
                                </p>
                                <p
                                    v-else-if="
                                        delivery.last_event === 'unsubscribed'
                                    "
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Unsubscribed from this email
                                </p>
                                <p
                                    v-else-if="delivery.delivered_at"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Delivered
                                    {{ formatDateTime(delivery.delivered_at) }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
