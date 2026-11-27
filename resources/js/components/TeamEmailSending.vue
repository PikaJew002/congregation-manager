<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type EmailSendingSettings = {
    hasApiKey: boolean;
    accountId: string | null;
    sendingDomain: string | null;
    dkimSelector: string | null;
    dailyEmailLimit: number;
    checkedAt: string | null;
    records: DnsRecord[];
    problem: string | null;
    deliveryEvents: {
        connected: boolean;
        url: string | null;
        appUrl: string;
    };
};

export type DnsRecord = {
    key: string;
    label: string;
    name: string | null;
    type: string;
    value: string | null;
    passed: boolean | null;
    reason: string | null;
};

const props = defineProps<{
    teamSlug: string;
    settings: EmailSendingSettings;
}>();

const form = useForm({
    mailchannels_api_key: '',
    clear_mailchannels_api_key: false,
    mailchannels_account_id: props.settings.accountId ?? '',
    sending_domain: props.settings.sendingDomain ?? '',
    daily_email_limit: props.settings.dailyEmailLimit,
});

const keyPlaceholder = computed(() => {
    if (form.clear_mailchannels_api_key) {
        return 'Will be cleared on save';
    }

    return props.settings.hasApiKey
        ? 'Saved. Leave blank to keep it.'
        : 'Not set';
});

const markKeyForClear = () => {
    form.clear_mailchannels_api_key = true;
    form.mailchannels_api_key = '';
};

const undoClearKey = () => {
    form.clear_mailchannels_api_key = false;
};

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const busy = ref<'dkim' | 'check' | 'delivery-events' | null>(null);
const copied = ref<string | null>(null);

const canSetUpDns = computed(
    () => props.settings.hasApiKey && !!props.settings.sendingDomain,
);

const checkedAt = computed(() =>
    props.settings.checkedAt
        ? new Date(props.settings.checkedAt).toLocaleString()
        : null,
);

const post = (action: 'dkim' | 'check' | 'delivery-events') => {
    router.post(
        `/settings/teams/${props.teamSlug}/email-sending/${action}`,
        {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = action),
            onFinish: () => (busy.value = null),
        },
    );
};

const copy = async (key: string, value: string) => {
    await navigator.clipboard.writeText(value);
    copied.value = key;
    setTimeout(() => (copied.value = null), 1500);
};

const submit = () => {
    form.patch(`/settings/teams/${props.teamSlug}/email-sending`, {
        preserveScroll: true,
        onSuccess: () =>
            form.reset('mailchannels_api_key', 'clear_mailchannels_api_key'),
    });
};
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Email sending"
            description="The congregation's own MailChannels account. Every email the congregation sends, such as the Shabbat invite or a newsletter, goes out through it."
        />

        <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="mailchannels_api_key">MailChannels API key</Label>
                <div class="flex gap-2">
                    <Input
                        id="mailchannels_api_key"
                        v-model="form.mailchannels_api_key"
                        type="password"
                        autocomplete="off"
                        :disabled="form.clear_mailchannels_api_key"
                        :placeholder="keyPlaceholder"
                    />
                    <Button
                        v-if="
                            settings.hasApiKey &&
                            !form.clear_mailchannels_api_key
                        "
                        type="button"
                        variant="outline"
                        @click="markKeyForClear"
                    >
                        Clear
                    </Button>
                    <Button
                        v-else-if="form.clear_mailchannels_api_key"
                        type="button"
                        variant="outline"
                        @click="undoClearKey"
                    >
                        Undo
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Create one in the MailChannels dashboard under API Keys. It
                    is checked with MailChannels when you save, and never shown
                    again.
                </p>
                <InputError :message="form.errors.mailchannels_api_key" />
            </div>

            <div class="grid gap-2">
                <Label for="mailchannels_account_id">Account ID</Label>
                <Input
                    id="mailchannels_account_id"
                    v-model="form.mailchannels_account_id"
                    autocomplete="off"
                />
                <p class="text-xs text-muted-foreground">
                    Shown at the bottom of the MailChannels dashboard. It goes
                    in the Domain Lockdown record for your domain.
                </p>
                <InputError :message="form.errors.mailchannels_account_id" />
            </div>

            <div class="grid gap-2">
                <Label for="sending_domain">Sending domain</Label>
                <Input
                    id="sending_domain"
                    v-model="form.sending_domain"
                    placeholder="example.org"
                    autocomplete="off"
                />
                <p class="text-xs text-muted-foreground">
                    The domain congregation email comes from. From addresses
                    must be on it. Changing it means setting up DKIM again.
                </p>
                <InputError :message="form.errors.sending_domain" />
            </div>

            <div class="grid gap-2">
                <Label for="daily_email_limit">Daily sending limit</Label>
                <Input
                    id="daily_email_limit"
                    v-model.number="form.daily_email_limit"
                    type="number"
                    min="1"
                    class="max-w-40"
                />
                <p class="text-xs text-muted-foreground">
                    How many emails a day your MailChannels plan allows. The
                    free plan allows 100. A send larger than this will not
                    start.
                </p>
                <InputError :message="form.errors.daily_email_limit" />
            </div>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>

        <div v-if="canSetUpDns" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm font-medium">
                        DNS records for {{ settings.sendingDomain }}
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Publish these at your DNS host. Sending stays off until
                        all three pass.
                        <template v-if="checkedAt">
                            Last checked {{ checkedAt }}.
                        </template>
                    </p>
                </div>
                <Badge :variant="settings.problem ? 'secondary' : 'default'">
                    {{
                        settings.problem ? 'Not ready to send' : 'Ready to send'
                    }}
                </Badge>
            </div>

            <div
                v-for="record in settings.records"
                :key="record.key"
                class="space-y-2 rounded-lg border p-4"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ record.label }}</span>
                    <Badge
                        v-if="record.passed !== null"
                        :variant="record.passed ? 'default' : 'destructive'"
                    >
                        {{ record.passed ? 'Passing' : 'Not passing' }}
                    </Badge>
                    <Badge v-else variant="outline">Not checked</Badge>
                </div>

                <template v-if="record.key === 'dkim' && !record.value">
                    <p class="text-sm text-muted-foreground">
                        MailChannels creates and keeps the DKIM key. You publish
                        the record it gives you.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="busy !== null"
                        @click="post('dkim')"
                    >
                        Create DKIM key
                    </Button>
                    <InputError :message="errors.dkim" />
                </template>

                <p
                    v-else-if="
                        record.key === 'domain_lockdown' && !record.value
                    "
                    class="text-sm text-muted-foreground"
                >
                    Save the account ID above to see this record.
                </p>

                <dl v-else class="grid gap-2 text-sm sm:grid-cols-[6rem_1fr]">
                    <dt class="text-muted-foreground">Type</dt>
                    <dd>{{ record.type }}</dd>
                    <dt class="text-muted-foreground">Name</dt>
                    <dd class="flex items-start gap-2">
                        <code class="break-all">{{ record.name }}</code>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-label="`Copy ${record.label} name`"
                            @click="
                                copy(`${record.key}-name`, record.name ?? '')
                            "
                        >
                            <Check
                                v-if="copied === `${record.key}-name`"
                                class="h-4 w-4"
                            />
                            <Copy v-else class="h-4 w-4" />
                        </Button>
                    </dd>
                    <dt class="text-muted-foreground">Value</dt>
                    <dd class="flex items-start gap-2">
                        <code class="break-all">{{ record.value }}</code>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-label="`Copy ${record.label} value`"
                            @click="
                                copy(`${record.key}-value`, record.value ?? '')
                            "
                        >
                            <Check
                                v-if="copied === `${record.key}-value`"
                                class="h-4 w-4"
                            />
                            <Copy v-else class="h-4 w-4" />
                        </Button>
                    </dd>
                </dl>

                <p
                    v-if="record.key === 'spf' && record.value"
                    class="text-xs text-muted-foreground"
                >
                    A domain has one SPF record. If yours already exists,
                    replace it with this one, which keeps what it had.
                </p>
                <p
                    v-if="record.passed === false && record.reason"
                    class="text-xs text-destructive"
                >
                    {{ record.reason }}
                </p>
            </div>

            <p v-if="settings.problem" class="text-sm text-muted-foreground">
                {{ settings.problem }}
            </p>

            <div class="flex items-center gap-4">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy !== null"
                    @click="post('check')"
                >
                    Check again
                </Button>
                <span class="text-xs text-muted-foreground">
                    DNS changes can take a few minutes to show.
                </span>
            </div>
            <InputError :message="errors.check" />
        </div>

        <div v-if="settings.hasApiKey" class="space-y-3 rounded-lg border p-4">
            <div class="flex items-center justify-between gap-2">
                <span class="font-medium">Delivery events</span>
                <Badge
                    :variant="
                        settings.deliveryEvents.connected
                            ? 'default'
                            : 'outline'
                    "
                >
                    {{
                        settings.deliveryEvents.connected
                            ? 'Connected'
                            : 'Not connected'
                    }}
                </Badge>
            </div>
            <p class="text-sm text-muted-foreground">
                Recommended. MailChannels tells the app when an email bounces or
                someone reports it as spam, so those addresses come off the
                list. Email still sends without it.
            </p>
            <p
                v-if="
                    !settings.deliveryEvents.connected &&
                    settings.deliveryEvents.url
                "
                class="text-sm"
            >
                This MailChannels account already sends its delivery events to
                <code class="break-all">{{ settings.deliveryEvents.url }}</code
                >. MailChannels allows one per account. To use this app instead,
                remove that webhook in MailChannels, then connect again.
            </p>
            <Button
                v-if="!settings.deliveryEvents.connected"
                type="button"
                variant="outline"
                :disabled="busy !== null"
                @click="post('delivery-events')"
            >
                Connect delivery events
            </Button>
            <InputError :message="errors.delivery_events" />
        </div>
    </div>
</template>
