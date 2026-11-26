<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { recipients as recipientsRoute } from '@/routes/weekly-email';
import { destroy, store, update } from '@/routes/weekly-email/recipients';
import type { Team } from '@/types';
import { formatDateTime, statusVariant } from '../format';
import type { EmailRecipient } from '../types';

const props = defineProps<{
    recipients: EmailRecipient[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Email list',
                href: recipientsRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
let search = ref('');

let subscribedCount = computed(
    () => props.recipients.filter((r) => r.status === 'subscribed').length,
);

let filteredRecipients = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return props.recipients;
    }

    return props.recipients.filter(
        (r) =>
            r.email.includes(term) ||
            (r.name ?? '').toLowerCase().includes(term),
    );
});

const setStatus = (
    recipient: EmailRecipient,
    status: EmailRecipient['status'],
) => {
    router.visit(update([teamSlug.value, recipient.id]), {
        data: { name: recipient.name, status },
        preserveScroll: true,
    });
};

const removeRecipient = (recipient: EmailRecipient) => {
    if (!confirm(`Remove ${recipient.email} from the list?`)) {
        return;
    }

    router.visit(destroy([teamSlug.value, recipient.id]), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Email list" />

    <div class="flex flex-col space-y-10 p-4">
        <div class="space-y-6">
            <Heading
                title="Email list"
                :description="`${subscribedCount} subscribed of ${recipients.length} total`"
            />

            <Form
                v-bind="store.form(teamSlug)"
                class="max-w-2xl space-y-4"
                reset-on-success
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="entries">Add recipients</Label>
                    <textarea
                        id="entries"
                        name="entries"
                        rows="5"
                        required
                        placeholder="jane@example.com, Jane Doe&#10;john@example.com"
                        class="min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    />
                    <p class="text-sm text-muted-foreground">
                        One per line, as an email address, optionally followed
                        by a comma and a name. Existing addresses are updated
                        and subscribed again, except people who reported the
                        email as spam (remove them first if they ask to get it
                        again). Invalid lines are skipped.
                    </p>
                    <InputError :message="errors.entries" />
                </div>

                <Button type="submit" :disabled="processing">
                    Add to list
                </Button>
            </Form>
        </div>

        <div class="space-y-4">
            <div class="flex items-center justify-between gap-4">
                <Heading variant="small" title="Recipients" />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search by name or email"
                    class="max-w-xs"
                />
            </div>

            <p
                v-if="recipients.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No one is on the list yet.
            </p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Email</th>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 font-medium">Added</th>
                            <th class="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="recipient in filteredRecipients"
                            :key="recipient.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 font-medium">
                                {{ recipient.email }}
                            </td>
                            <td class="px-4 py-2">
                                {{ recipient.name ?? '—' }}
                            </td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="statusVariant(recipient.status)"
                                >
                                    {{ recipient.status }}
                                </Badge>
                                <div
                                    v-if="recipient.status_reason"
                                    class="mt-1 max-w-xs text-xs break-words text-muted-foreground"
                                >
                                    {{ recipient.status_reason }}
                                </div>
                                <div
                                    v-if="recipient.unsubscribed_at"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{
                                        formatDateTime(
                                            recipient.unsubscribed_at,
                                        )
                                    }}
                                </div>
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ formatDateTime(recipient.created_at) }}
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        v-if="recipient.status === 'subscribed'"
                                        variant="outline"
                                        size="sm"
                                        @click="
                                            setStatus(recipient, 'unsubscribed')
                                        "
                                    >
                                        Unsubscribe
                                    </Button>
                                    <Button
                                        v-else-if="
                                            recipient.status !== 'complained'
                                        "
                                        variant="outline"
                                        size="sm"
                                        @click="
                                            setStatus(recipient, 'subscribed')
                                        "
                                    >
                                        Resubscribe
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        :aria-label="`Remove ${recipient.email}`"
                                        @click="removeRecipient(recipient)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filteredRecipients.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                No recipients match “{{ search }}”.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
