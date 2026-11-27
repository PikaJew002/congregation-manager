<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { settings as settingsRoute } from '@/routes/email';
import { update } from '@/routes/email/settings';
import type { Team } from '@/types';
import VariablesEditor from '../components/VariablesEditor.vue';
import type { EmailSettings } from '../types';

const props = defineProps<{
    settings: EmailSettings;
    sendingDomain: string | null;
    /** Variable name => the emails that use the shared value. */
    usage: Record<string, string[]>;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Sender & variables',
                href: settingsRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

let form = useForm({
    from_name: props.settings.from_name,
    from_address: props.settings.from_address,
    reply_to: props.settings.reply_to ?? '',
    variables: (props.settings.variables ?? []).map((variable) => ({
        ...variable,
    })),
});

let usedBy = computed(() =>
    form.variables
        .filter((variable) => props.usage[variable.key]?.length)
        .map((variable) => ({
            key: variable.key,
            emails: props.usage[variable.key],
        })),
);

const token = (key: string) => `{{${key}}}`;

const submit = () => {
    form.submit(update(teamSlug.value), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
};
</script>

<template>
    <Head title="Sender & variables" />

    <div class="flex max-w-3xl flex-col space-y-10 p-4">
        <Heading
            title="Sender & variables"
            description="What every email shares"
        />

        <form class="space-y-10" @submit.prevent="submit">
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
                </div>
            </div>

            <div class="space-y-6">
                <Heading
                    variant="small"
                    title="Shared variables"
                    description="Any email can use these, such as {{zoom_url}}. Change one here and every email that uses it changes. An email can give a variable its own value instead."
                />

                <VariablesEditor
                    v-model="form.variables"
                    :errors="form.errors as Record<string, string | undefined>"
                />

                <ul
                    v-if="usedBy.length"
                    class="space-y-1 rounded-lg bg-muted/40 p-4 text-sm"
                >
                    <li v-for="item in usedBy" :key="item.key">
                        <code class="rounded bg-muted px-1 py-0.5">{{
                            token(item.key)
                        }}</code>
                        <span class="text-muted-foreground">
                            is used by {{ item.emails.join(', ') }}</span
                        >
                    </li>
                </ul>
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing">
                    Save
                </Button>
                <span v-if="form.isDirty" class="text-sm text-muted-foreground">
                    Unsaved changes
                </span>
            </div>
        </form>
    </div>
</template>
