<script setup lang="ts">
import { Form, Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit as editTeam } from '@/routes/teams';
import { template as templateRoute } from '@/routes/weekly-email';
import { preview, test, update } from '@/routes/weekly-email/template';
import type { Team } from '@/types';
import type { WeeklyEmailSettings } from '../types';

const props = defineProps<{
    settings: WeeklyEmailSettings;
    sendingProblem: string | null;
    sendingDomain: string | null;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Email template',
                href: templateRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

let form = useForm({
    subject: props.settings.subject,
    body: props.settings.body,
    zoom_link: props.settings.zoom_link ?? '',
    from_address: props.settings.from_address,
    from_name: props.settings.from_name,
    reply_to: props.settings.reply_to ?? '',
});

const placeholders = [
    { token: '{{name}}', help: 'Recipient name, or “friend” if blank' },
    { token: '{{zoom_link}}', help: 'The Zoom link above' },
    { token: '{{unsubscribe_url}}', help: 'Personal unsubscribe link' },
];

const unsubscribeToken = '{{unsubscribe_url}}';

let missingUnsubscribe = computed(() => !form.body.includes(unsubscribeToken));

let previewSubject = ref('');
let previewHtml = ref('');
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
                zoom_link: form.zoom_link,
            }),
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data: { subject: string; html: string } = await response.json();
        previewSubject.value = data.subject;
        previewHtml.value = data.html;
        previewError.value = false;
    } catch {
        previewError.value = true;
    }
};

onMounted(refreshPreview);

watchDebounced(
    () => [form.subject, form.body, form.zoom_link],
    refreshPreview,
    { debounce: 400 },
);

const submit = () => {
    form.submit(update(teamSlug.value), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
};
</script>

<template>
    <Head title="Email template" />

    <div class="flex flex-col space-y-10 p-4">
        <Heading
            title="Email template"
            description="What goes out each week. The body is Markdown."
        />

        <div class="grid gap-10 xl:grid-cols-2">
            <form class="space-y-8" @submit.prevent="submit">
                <div class="space-y-6">
                    <Heading variant="small" title="Sender" />

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="from_name">From name</Label>
                            <Input
                                id="from_name"
                                v-model="form.from_name"
                                required
                            />
                            <InputError :message="form.errors.from_name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="from_address">From address</Label>
                            <Input
                                id="from_address"
                                v-model="form.from_address"
                                type="email"
                                required
                            />
                            <p
                                v-if="sendingDomain"
                                class="text-xs text-muted-foreground"
                            >
                                Must end in @{{ sendingDomain }}.
                            </p>
                            <InputError :message="form.errors.from_address" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="reply_to">Reply-to (optional)</Label>
                            <Input
                                id="reply_to"
                                v-model="form.reply_to"
                                type="email"
                            />
                            <InputError :message="form.errors.reply_to" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="zoom_link">Zoom link</Label>
                            <Input
                                id="zoom_link"
                                v-model="form.zoom_link"
                                type="url"
                                placeholder="Zoom meeting URL"
                            />
                            <InputError :message="form.errors.zoom_link" />
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <Heading variant="small" title="Message" />

                    <div class="grid gap-2">
                        <Label for="subject">Subject</Label>
                        <Input id="subject" v-model="form.subject" required />
                        <InputError :message="form.errors.subject" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="body">Body</Label>
                        <textarea
                            id="body"
                            v-model="form.body"
                            rows="14"
                            required
                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                        <InputError :message="form.errors.body" />
                        <p
                            v-if="missingUnsubscribe"
                            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                        >
                            The body has no
                            <code>{{ unsubscribeToken }}</code
                            >. Recipients won't see an unsubscribe link in the
                            email itself, only the one their mail app shows.
                        </p>
                    </div>

                    <div class="rounded-lg border bg-muted/30 p-4 text-sm">
                        <p class="mb-2 font-medium">Placeholders</p>
                        <ul class="space-y-1">
                            <li v-for="item in placeholders" :key="item.token">
                                <code class="rounded bg-muted px-1 py-0.5">{{
                                    item.token
                                }}</code>
                                <span class="text-muted-foreground">
                                    — {{ item.help }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        Save template
                    </Button>
                    <span
                        v-if="form.isDirty"
                        class="text-sm text-muted-foreground"
                    >
                        Unsaved changes
                    </span>
                </div>
            </form>

            <div class="space-y-4 xl:sticky xl:top-4 xl:self-start">
                <Heading
                    variant="small"
                    title="Preview"
                    :description="`As ${page.props.auth.user.name} would see it`"
                />

                <div class="overflow-hidden rounded-lg border">
                    <div class="border-b bg-muted/40 px-4 py-2 text-sm">
                        <span class="text-muted-foreground">Subject:</span>
                        {{ previewSubject || '—' }}
                    </div>
                    <p v-if="previewError" class="p-4 text-sm text-destructive">
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

                <div class="space-y-3 border-t pt-6">
                    <Heading
                        variant="small"
                        title="Send a test"
                        :description="`Sends the saved template to ${page.props.auth.user.email} right away. It isn't added to the list or the run history.`"
                    />
                    <Form
                        v-bind="test.form(teamSlug)"
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
            </div>
        </div>
    </div>
</template>
