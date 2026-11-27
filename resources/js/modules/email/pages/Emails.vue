<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, edit, index as indexRoute } from '@/routes/email';
import { edit as editTeam } from '@/routes/teams';
import type { Team } from '@/types';
import { describeSchedule, formatDateTime } from '../format';
import type { Email } from '../types';

const props = defineProps<{
    emails: Email[];
    sendingProblem: string | null;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Emails',
                href: indexRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

type Filter = 'all' | 'recurring' | 'once' | 'draft';

const filters: { value: Filter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'recurring', label: 'Recurring' },
    { value: 'once', label: 'One-time' },
    { value: 'draft', label: 'Drafts' },
];

let filter = ref<Filter>('all');

let shown = computed(() =>
    props.emails.filter((email) => {
        switch (filter.value) {
            case 'recurring':
                return email.send_mode === 'recurring';
            case 'once':
                return email.send_mode === 'once';
            case 'draft':
                return email.status === 'draft';
            default:
                return true;
        }
    }),
);

const statusLabel = (email: Email) => {
    if (email.status === 'sent') {
        return 'Sent';
    }

    if (email.status === 'draft') {
        return email.send_mode === 'recurring' ? 'Off' : 'Draft';
    }

    return email.send_mode === 'recurring' ? 'On' : 'Scheduled';
};

const statusVariant = (email: Email) =>
    email.status === 'scheduled'
        ? 'default'
        : email.status === 'sent'
          ? 'secondary'
          : 'outline';
</script>

<template>
    <Head title="Emails" />

    <div class="flex flex-col space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Emails"
                description="Recurring emails, like the Shabbat invite, and one-time emails, like a newsletter"
            />
            <Button as-child>
                <Link :href="create(teamSlug)">
                    <Plus class="size-4" /> New email
                </Link>
            </Button>
        </div>

        <div
            v-if="sendingProblem"
            class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-200/10 dark:bg-amber-700/10 dark:text-amber-100"
        >
            <p class="font-medium">Email sending isn't set up yet</p>
            <p>
                {{ sendingProblem }}
                <Link
                    v-if="teamSlug"
                    :href="editTeam(teamSlug)"
                    class="underline underline-offset-4"
                    >Open congregation settings</Link
                >
            </p>
        </div>

        <div class="flex flex-wrap gap-2" role="tablist">
            <Button
                v-for="item in filters"
                :key="item.value"
                type="button"
                size="sm"
                role="tab"
                :aria-selected="filter === item.value"
                :variant="filter === item.value ? 'secondary' : 'ghost'"
                @click="filter = item.value"
            >
                {{ item.label }}
            </Button>
        </div>

        <p
            v-if="shown.length === 0"
            class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            <template v-if="emails.length === 0">
                No emails yet. Start one with New email.
            </template>
            <template v-else>No emails here.</template>
        </p>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">When</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Next</th>
                        <th class="px-4 py-2 font-medium">Last sent</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="email in shown"
                        :key="email.id ?? 0"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-2">
                            <Link
                                :href="edit([teamSlug, email.id ?? 0])"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ email.name }}
                            </Link>
                            <div class="text-muted-foreground">
                                {{ email.subject }}
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            {{ describeSchedule(email) }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge :variant="statusVariant(email)">
                                {{ statusLabel(email) }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ formatDateTime(email.next_send_at) }}
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ formatDateTime(email.last_sent_at ?? null) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
