<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { settings as settingsRoute } from '@/routes/zoom-recordings';
import { update } from '@/routes/zoom-recordings/settings';
import type { Team } from '@/types';
import type { ZoomRecordingSettings } from '../types';

const props = defineProps<{
    settings: ZoomRecordingSettings;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Recording settings',
                href: settingsRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

const recordingTypes = [
    {
        value: 'shared_screen_with_speaker_view',
        label: 'Shared screen with speaker view',
    },
    {
        value: 'shared_screen_with_gallery_view',
        label: 'Shared screen with gallery view',
    },
    { value: 'speaker_view', label: 'Speaker view' },
    { value: 'gallery_view', label: 'Gallery view' },
    { value: 'shared_screen', label: 'Shared screen only' },
    { value: 'active_speaker', label: 'Active speaker' },
];

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

let form = useForm({
    webhook_secret: '',
    clear_webhook_secret: false,
    r2_prefix: props.settings.r2_prefix,
    public_base_url: props.settings.public_base_url ?? '',
    preferred_recording_type: props.settings.preferred_recording_type,
});

let typeOptions = computed(() =>
    recordingTypes.some((t) => t.value === form.preferred_recording_type)
        ? recordingTypes
        : [
              {
                  value: form.preferred_recording_type,
                  label: form.preferred_recording_type,
              },
              ...recordingTypes,
          ],
);

let manifestUrl = computed(() => {
    const base = form.public_base_url.replace(/\/+$/, '');
    const prefix = form.r2_prefix.replace(/^\/+|\/+$/g, '');

    return base ? `${base}/${prefix}/recordings.json` : null;
});

let secretPlaceholder = computed(() => {
    if (form.clear_webhook_secret) {
        return 'Will be cleared on save';
    }

    return props.settings.has_webhook_secret
        ? 'Saved. Leave blank to keep it.'
        : 'Not set';
});

const copyWebhookUrl = async () => {
    await navigator.clipboard.writeText(props.settings.webhook_url);
    toast.success('Webhook URL copied.');
};

const markSecretForClear = () => {
    form.clear_webhook_secret = true;
    form.webhook_secret = '';
};

const undoClearSecret = () => {
    form.clear_webhook_secret = false;
};

const submit = () => {
    form.submit(update(teamSlug.value), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('webhook_secret', 'clear_webhook_secret');
            form.defaults();
        },
    });
};
</script>

<template>
    <Head title="Recording settings" />

    <div class="flex max-w-3xl flex-col space-y-10 p-4">
        <Heading
            title="Recording settings"
            description="Connect Zoom and choose where recordings are published"
        />

        <form class="space-y-10" @submit.prevent="submit">
            <section class="space-y-6">
                <Heading
                    variant="small"
                    title="Zoom webhook"
                    description="In your Zoom Marketplace app, subscribe to the recording.completed event and paste this URL as the endpoint."
                />

                <div class="grid gap-2">
                    <Label for="webhook_url">Webhook URL</Label>
                    <div class="flex gap-2">
                        <Input
                            id="webhook_url"
                            :model-value="settings.webhook_url"
                            readonly
                            class="font-mono text-xs"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            aria-label="Copy webhook URL"
                            @click="copyWebhookUrl"
                        >
                            <Copy class="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="webhook_secret">Secret token</Label>
                    <div class="flex gap-2">
                        <Input
                            id="webhook_secret"
                            v-model="form.webhook_secret"
                            type="password"
                            autocomplete="off"
                            :disabled="form.clear_webhook_secret"
                            :placeholder="secretPlaceholder"
                        />
                        <Button
                            v-if="
                                settings.has_webhook_secret &&
                                !form.clear_webhook_secret
                            "
                            type="button"
                            variant="outline"
                            @click="markSecretForClear"
                        >
                            Clear
                        </Button>
                        <Button
                            v-else-if="form.clear_webhook_secret"
                            type="button"
                            variant="outline"
                            @click="undoClearSecret"
                        >
                            Undo
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        From the Feature tab of your Zoom app. Save it here
                        before clicking Validate in Zoom; the webhook ignores
                        all calls until a secret is saved.
                    </p>
                    <InputError :message="form.errors.webhook_secret" />
                    <InputError :message="form.errors.clear_webhook_secret" />
                </div>
            </section>

            <section class="space-y-6">
                <Heading
                    variant="small"
                    title="Publishing"
                    description="Where the converted recordings and the website list are stored"
                />

                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="r2_prefix">Storage folder</Label>
                        <Input
                            id="r2_prefix"
                            v-model="form.r2_prefix"
                            required
                        />
                        <InputError :message="form.errors.r2_prefix" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="public_base_url">Public base URL</Label>
                        <Input
                            id="public_base_url"
                            v-model="form.public_base_url"
                            type="url"
                        />
                        <InputError :message="form.errors.public_base_url" />
                    </div>
                </div>

                <p
                    v-if="manifestUrl"
                    class="rounded-lg bg-muted/40 p-4 text-sm break-all"
                >
                    The website player reads
                    <code class="font-mono text-xs">{{ manifestUrl }}</code>
                </p>

                <div class="grid gap-2">
                    <Label for="preferred_recording_type">
                        Which recording to use
                    </Label>
                    <select
                        id="preferred_recording_type"
                        v-model="form.preferred_recording_type"
                        :class="selectClass"
                    >
                        <option
                            v-for="type in typeOptions"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Zoom can save several layouts of the same meeting. If
                        this one is missing, the first MP4 is used.
                    </p>
                    <InputError
                        :message="form.errors.preferred_recording_type"
                    />
                </div>
            </section>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing">
                    Save settings
                </Button>
                <span v-if="form.isDirty" class="text-sm text-muted-foreground">
                    Unsaved changes
                </span>
            </div>
        </form>
    </div>
</template>
