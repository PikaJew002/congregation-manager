<script setup lang="ts">
import { Form, Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import CongregationTimezone from '@/components/CongregationTimezone.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy,
    duplicate,
    edit,
    index as indexRoute,
    preview,
    settings as settingsRoute,
    store,
    test,
    update,
} from '@/routes/email';
import { show as showRun } from '@/routes/email/runs';
import { edit as editTeam } from '@/routes/teams';
import type { Team } from '@/types';
import VariablesEditor from '../components/VariablesEditor.vue';
import {
    describeSchedule,
    formatDateTime,
    statusVariant,
    weekdays,
} from '../format';
import type { Email, EmailRun, EmailVariable } from '../types';

const props = defineProps<{
    email: Email;
    sharedVariables: EmailVariable[];
    sender: { from_name: string; from_address: string };
    runs: EmailRun[];
    sendingProblem: string | null;
    subscribers: number;
    estimatedMinutes: number;
    dailyEmailLimit: number;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null; email: Email }) => {
        const slug = props.currentTeam?.slug ?? '';

        return {
            breadcrumbs: [
                { title: 'Emails', href: indexRoute(slug) },
                props.email.id
                    ? {
                          title: props.email.name,
                          href: edit([slug, props.email.id]),
                      }
                    : { title: 'New email', href: indexRoute(slug) },
            ],
        };
    },
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
let isNew = computed(() => props.email.id === null);
let isSent = computed(() => props.email.status === 'sent');

let form = useForm({
    name: props.email.name,
    send_mode: props.email.send_mode,
    status: props.email.status === 'sent' ? 'draft' : props.email.status,
    send_days: [...props.email.send_days],
    send_time: props.email.send_time,
    send_at: props.email.send_at ?? '',
    subject: props.email.subject,
    body: props.email.body,
    variables: props.email.variables.map((variable) => ({ ...variable })),
});

let errors = computed(() => form.errors as Record<string, string | undefined>);

const token = (key: string) => `{{${key}}}`;

const unsubscribeToken = token('unsubscribe_url');

let missingUnsubscribe = computed(() => !form.body.includes(unsubscribeToken));

const toggleDay = (day: number, on: boolean) => {
    form.send_days = on
        ? [...new Set([...form.send_days, day])].sort((a, b) => a - b)
        : form.send_days.filter((value) => value !== day);
};

let timezone = computed(
    () => page.props.currentTeam?.timezone ?? 'America/New_York',
);

let duration = computed(() => {
    if (props.subscribers <= 1) {
        return 'goes out right away';
    }

    const minutes = Math.max(props.estimatedMinutes, 1);

    return `goes to ${props.subscribers} people one at a time, a few seconds apart, and takes about ${minutes} minute${minutes === 1 ? '' : 's'}`;
});

let summary = computed(
    () =>
        `${describeSchedule(form)} (${timezone.value}), the email ${duration.value}.`,
);

let overLimit = computed(() => props.subscribers > props.dailyEmailLimit);

// Placeholders, with what each fills in. Shared variables an email
// overrides show the email's value.
let placeholders = computed(() => {
    const own = new Map(
        form.variables
            .filter((variable) => variable.key)
            .map((variable) => [variable.key, variable.value]),
    );
    const shared = props.sharedVariables
        .filter((variable) => !own.has(variable.key))
        .map((variable) => ({
            token: token(variable.key),
            help: variable.value || '(empty)',
            source: 'shared',
        }));

    return [
        {
            token: token('name'),
            help: 'Recipient name, or “friend” if blank',
            source: 'built in',
        },
        {
            token: token('unsubscribe_url'),
            help: 'Personal unsubscribe link',
            source: 'built in',
        },
        ...[...own].map(([key, value]) => ({
            token: token(key),
            help: value || '(empty)',
            source: 'this email',
        })),
        ...shared,
    ];
});

const dateExamples = [
    {
        token: '{{date:saturday|M j, Y}}',
        help: 'The Saturday on or after the send date, like Nov 28, 2026',
    },
    {
        token: '{{date:next friday|l, F j}}',
        help: 'The Friday after the send date, never the same day',
    },
    {
        token: '{{date:+1 month|F Y}}',
        help: 'A month after the send date, like December 2026',
    },
    { token: '{{date}}', help: 'The send date itself' },
];

let bodyField = ref<HTMLTextAreaElement | null>(null);

// Put a placeholder where the cursor is in the body.
const insert = (text: string) => {
    if (isSent.value) {
        return;
    }

    const field = bodyField.value;
    const start = field?.selectionStart ?? form.body.length;
    const end = field?.selectionEnd ?? form.body.length;
    form.body = form.body.slice(0, start) + text + form.body.slice(end);

    requestAnimationFrame(() => {
        field?.focus();
        field?.setSelectionRange(start + text.length, start + text.length);
    });
};

let previewSubject = ref('');
let previewHtml = ref('');
let previewSendsAt = ref<string | null>(null);
let previewProblems = ref<string[]>([]);
let previewError = ref(false);

const previewStyles =
    '<style>body{font-family:system-ui,sans-serif;font-size:14px;line-height:1.6;color:#111;margin:16px;}a{color:#2563eb;}</style>';

let previewDocument = computed(() => previewStyles + previewHtml.value);

const xsrfToken = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

const refreshPreview = async () => {
    try {
        const response = await fetch(preview.url(teamSlug.value), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                subject: form.subject,
                body: form.body,
                variables: form.variables,
                send_mode: form.send_mode,
                send_days: form.send_days,
                send_time: form.send_time || null,
                send_at: form.send_at || null,
            }),
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data: {
            subject: string;
            html: string;
            sendsAt: string;
            problems: string[];
        } = await response.json();
        previewSubject.value = data.subject;
        previewHtml.value = data.html;
        previewSendsAt.value = data.sendsAt;
        previewProblems.value = data.problems;
        previewError.value = false;
    } catch {
        previewError.value = true;
    }
};

onMounted(refreshPreview);

watchDebounced(
    () => [
        form.subject,
        form.body,
        form.send_mode,
        form.send_time,
        form.send_at,
        JSON.stringify(form.send_days),
        JSON.stringify(form.variables),
    ],
    refreshPreview,
    { debounce: 400 },
);

const save = (
    overrides: { status?: 'draft' | 'scheduled'; send_now?: boolean } = {},
) => {
    const target = isNew.value
        ? store(teamSlug.value)
        : update([teamSlug.value, props.email.id as number]);

    form.transform((data) => ({ ...data, ...overrides })).submit(target, {
        preserveScroll: true,
        onSuccess: () => {
            if (overrides.status) {
                form.status = overrides.status;
            }

            form.defaults();
        },
    });
};

const sendNow = () => {
    if (
        !confirm(
            `Send “${form.subject}” to ${props.subscribers} ${props.subscribers === 1 ? 'person' : 'people'} now?`,
        )
    ) {
        return;
    }

    save({ status: 'scheduled', send_now: true });
};

const deleteEmail = () => {
    if (
        !confirm(
            `Delete “${props.email.name}”? What it already sent stays under Sent.`,
        )
    ) {
        return;
    }

    router.delete(destroy([teamSlug.value, props.email.id as number]));
};

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';
</script>

<template>
    <Head :title="isNew ? 'New email' : email.name" />

    <div class="flex flex-col space-y-10 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="isNew ? 'New email' : email.name"
                description="The body is Markdown. Placeholders in the subject and body are filled in when it goes out."
            />

            <div v-if="!isNew" class="flex gap-2">
                <Form
                    v-bind="duplicate.form([teamSlug, email.id as number])"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="secondary"
                        :disabled="processing"
                    >
                        Duplicate
                    </Button>
                </Form>
                <Button variant="ghost" @click="deleteEmail">Delete</Button>
            </div>
        </div>

        <p v-if="isSent" class="rounded-lg border bg-muted/40 p-4 text-sm">
            This one-time email was sent
            {{ formatDateTime(runs[0]?.scheduled_for ?? null) }} and can no
            longer change. Duplicate it to send something like it again.
        </p>

        <div class="grid gap-10 xl:grid-cols-2">
            <form class="space-y-10" @submit.prevent="save()">
                <fieldset :disabled="isSent" class="space-y-10">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            placeholder="Shabbat Zoom invite"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Only you see this. Recipients see the subject.
                        </p>
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-6">
                        <Heading variant="small" title="When it goes out" />

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label
                                v-for="mode in [
                                    {
                                        value: 'recurring',
                                        title: 'Recurring',
                                        help: 'On the days you choose, every week',
                                    },
                                    {
                                        value: 'once',
                                        title: 'One-time',
                                        help: 'Once, at a date and time, or right now',
                                    },
                                ] as const"
                                :key="mode.value"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border p-4 has-[:checked]:border-primary has-[:checked]:bg-muted/40"
                            >
                                <input
                                    v-model="form.send_mode"
                                    type="radio"
                                    name="send_mode"
                                    :value="mode.value"
                                    class="mt-1"
                                />
                                <span>
                                    <span class="block font-medium">{{
                                        mode.title
                                    }}</span>
                                    <span
                                        class="block text-sm text-muted-foreground"
                                        >{{ mode.help }}</span
                                    >
                                </span>
                            </label>
                        </div>

                        <template v-if="form.send_mode === 'recurring'">
                            <div class="grid gap-2">
                                <Label>Days</Label>
                                <div class="flex flex-wrap gap-2">
                                    <label
                                        v-for="(day, index) in weekdays"
                                        :key="day"
                                        class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-1.5 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted/40"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="
                                                form.send_days.includes(index)
                                            "
                                            @change="
                                                toggleDay(
                                                    index,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).checked,
                                                )
                                            "
                                        />
                                        {{ day.slice(0, 3) }}
                                    </label>
                                </div>
                                <InputError :message="form.errors.send_days" />
                            </div>

                            <div class="grid gap-6 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="send_time">Time</Label>
                                    <Input
                                        id="send_time"
                                        v-model="form.send_time"
                                        type="time"
                                        required
                                    />
                                    <InputError
                                        :message="form.errors.send_time"
                                    />
                                </div>

                                <CongregationTimezone
                                    usage="The time is in this time zone."
                                />
                            </div>

                            <label
                                class="flex items-start justify-between gap-4 rounded-lg border p-4"
                            >
                                <span>
                                    <span class="block font-medium">On</span>
                                    <span
                                        class="block text-sm text-muted-foreground"
                                    >
                                        When off, nothing goes out. Everything
                                        here is kept.
                                    </span>
                                </span>
                                <Checkbox
                                    :model-value="form.status === 'scheduled'"
                                    @update:model-value="
                                        (value: boolean | 'indeterminate') =>
                                            (form.status =
                                                value === true
                                                    ? 'scheduled'
                                                    : 'draft')
                                    "
                                />
                            </label>
                        </template>

                        <div v-else class="grid gap-6 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="send_at">Date and time</Label>
                                <Input
                                    id="send_at"
                                    v-model="form.send_at"
                                    type="datetime-local"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Leave it empty to keep a draft, or send it
                                    now.
                                </p>
                                <InputError :message="form.errors.send_at" />
                            </div>

                            <CongregationTimezone
                                usage="The time is in this time zone."
                            />
                        </div>

                        <InputError :message="form.errors.status" />

                        <p
                            v-if="
                                form.send_mode === 'recurring' &&
                                form.status === 'scheduled'
                            "
                            class="rounded-lg bg-muted/40 p-4 text-sm"
                        >
                            {{ summary }}
                        </p>

                        <p
                            v-if="overLimit"
                            class="rounded-lg border border-red-100 bg-red-50 p-4 text-sm text-red-700 dark:border-red-200/10 dark:bg-red-700/10 dark:text-red-100"
                        >
                            The list has {{ subscribers }} subscribers, but the
                            congregation's MailChannels plan allows
                            {{ dailyEmailLimit }} emails a day. Emails will not
                            go out until the limit is raised in the congregation
                            settings.
                        </p>
                    </div>

                    <div class="space-y-6">
                        <Heading variant="small" title="Message" />

                        <p class="text-sm text-muted-foreground">
                            From {{ sender.from_name }} &lt;{{
                                sender.from_address
                            }}&gt;.
                            <Link
                                :href="settingsRoute(teamSlug)"
                                class="underline underline-offset-4"
                                >Change the sender</Link
                            >
                        </p>

                        <div class="grid gap-2">
                            <Label for="subject">Subject</Label>
                            <Input
                                id="subject"
                                v-model="form.subject"
                                placeholder="Shabbat Service – {{date:saturday|M j}}"
                                required
                            />
                            <InputError :message="form.errors.subject" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="body">Body</Label>
                            <textarea
                                id="body"
                                ref="bodyField"
                                v-model="form.body"
                                rows="16"
                                required
                                :class="textareaClass"
                            />
                            <InputError :message="form.errors.body" />
                            <p
                                v-if="missingUnsubscribe"
                                class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                            >
                                The body has no
                                <code>{{ unsubscribeToken }}</code
                                >. Recipients won't see an unsubscribe link in
                                the email itself, only the one their mail app
                                shows.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <Heading
                            variant="small"
                            title="This email's variables"
                            description="Values only this email uses, such as a meeting ID. One with the same name as a shared variable replaces it for this email."
                        />
                        <VariablesEditor
                            v-model="form.variables"
                            :errors="errors"
                            :inherited="sharedVariables"
                            :disabled="isSent"
                        />
                    </div>

                    <div
                        class="space-y-4 rounded-lg border bg-muted/30 p-4 text-sm"
                    >
                        <div>
                            <p class="mb-2 font-medium">Placeholders</p>
                            <p class="mb-2 text-xs text-muted-foreground">
                                Click one to put it in the body.
                                <Link
                                    :href="settingsRoute(teamSlug)"
                                    class="underline underline-offset-4"
                                    >Edit shared variables</Link
                                >
                            </p>
                            <ul class="space-y-1">
                                <li
                                    v-for="item in placeholders"
                                    :key="item.token"
                                >
                                    <button
                                        type="button"
                                        class="rounded bg-muted px-1 py-0.5 font-mono hover:bg-muted/70"
                                        @click="insert(item.token)"
                                    >
                                        {{ item.token }}
                                    </button>
                                    <span class="text-muted-foreground">
                                        — {{ item.help }}
                                        <span class="text-xs"
                                            >({{ item.source }})</span
                                        ></span
                                    >
                                </li>
                            </ul>
                        </div>

                        <div>
                            <p class="mb-2 font-medium">Dates</p>
                            <p class="mb-2 text-xs text-muted-foreground">
                                Worked out from when the email goes out. Before
                                the | goes a day in plain words; after it, how
                                to write the date (M j, Y is Nov 28, 2026; l is
                                Saturday; F is November; g:i A is 11:00 AM).
                            </p>
                            <ul class="space-y-1">
                                <li
                                    v-for="item in dateExamples"
                                    :key="item.token"
                                >
                                    <button
                                        type="button"
                                        class="rounded bg-muted px-1 py-0.5 font-mono hover:bg-muted/70"
                                        @click="insert(item.token)"
                                    >
                                        {{ item.token }}
                                    </button>
                                    <span class="text-muted-foreground">
                                        — {{ item.help }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </div>
                </fieldset>

                <div v-if="!isSent" class="flex flex-wrap items-center gap-3">
                    <template v-if="form.send_mode === 'recurring'">
                        <Button type="submit" :disabled="form.processing">
                            Save
                        </Button>
                    </template>
                    <template v-else>
                        <Button
                            type="button"
                            :disabled="form.processing || !form.send_at"
                            @click="save({ status: 'scheduled' })"
                        >
                            Schedule
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="form.processing"
                            @click="save({ status: 'draft' })"
                        >
                            Save draft
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing || !!sendingProblem"
                            @click="sendNow"
                        >
                            Send now
                        </Button>
                    </template>
                    <span
                        v-if="form.isDirty"
                        class="text-sm text-muted-foreground"
                    >
                        Unsaved changes
                    </span>
                    <span
                        v-else-if="
                            email.send_mode === 'once' &&
                            email.status === 'scheduled'
                        "
                        class="text-sm text-muted-foreground"
                    >
                        Scheduled for {{ formatDateTime(email.next_send_at) }}
                    </span>
                </div>
            </form>

            <div class="space-y-8 xl:sticky xl:top-4 xl:self-start">
                <div class="space-y-4">
                    <Heading
                        variant="small"
                        title="Preview"
                        :description="`As ${page.props.auth.user.name} would see it${previewSendsAt ? `, sent ${formatDateTime(previewSendsAt)}` : ''}`"
                    />

                    <ul
                        v-if="previewProblems.length"
                        class="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                    >
                        <li v-for="problem in previewProblems" :key="problem">
                            {{ problem }}
                        </li>
                    </ul>

                    <div class="overflow-hidden rounded-lg border">
                        <div class="border-b bg-muted/40 px-4 py-2 text-sm">
                            <span class="text-muted-foreground">Subject:</span>
                            {{ previewSubject || '—' }}
                        </div>
                        <p
                            v-if="previewError"
                            class="p-4 text-sm text-destructive"
                        >
                            The preview couldn't be loaded.
                        </p>
                        <iframe
                            v-else
                            title="Email preview"
                            sandbox=""
                            :srcdoc="previewDocument"
                            class="h-[28rem] w-full bg-white"
                        />
                    </div>
                </div>

                <div v-if="!isNew" class="space-y-3 border-t pt-6">
                    <Heading
                        variant="small"
                        title="Send a test"
                        :description="`Sends the saved email to ${page.props.auth.user.email} right away. It isn't added to the list or to Sent.`"
                    />
                    <Form
                        v-bind="test.form([teamSlug, email.id as number])"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="secondary"
                            :disabled="
                                processing || form.isDirty || !!sendingProblem
                            "
                        >
                            Send test email
                        </Button>
                    </Form>
                    <p
                        v-if="sendingProblem"
                        class="text-xs text-muted-foreground"
                    >
                        {{ sendingProblem }}
                        <Link
                            v-if="teamSlug"
                            :href="editTeam(teamSlug)"
                            class="underline underline-offset-4"
                            >Open congregation settings</Link
                        >
                    </p>
                    <p
                        v-else-if="form.isDirty"
                        class="text-xs text-muted-foreground"
                    >
                        Save your changes before sending a test.
                    </p>
                </div>

                <div v-if="runs.length" class="space-y-3 border-t pt-6">
                    <Heading variant="small" title="Recent sends" />
                    <ul class="space-y-2 text-sm">
                        <li
                            v-for="run in runs"
                            :key="run.id"
                            class="flex items-center justify-between gap-4"
                        >
                            <Link
                                :href="showRun([teamSlug, run.id])"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ formatDateTime(run.scheduled_for) }}
                            </Link>
                            <span class="flex items-center gap-2">
                                <span class="text-muted-foreground tabular-nums"
                                    >{{ run.sent_count ?? 0 }} of
                                    {{ run.deliveries_count ?? 0 }}</span
                                >
                                <Badge :variant="statusVariant(run.status)">
                                    {{ run.status }}
                                </Badge>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
