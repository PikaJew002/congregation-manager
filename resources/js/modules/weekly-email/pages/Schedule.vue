<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CongregationTimezone from '@/components/CongregationTimezone.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit as editTeam } from '@/routes/teams';
import { schedule as scheduleRoute } from '@/routes/weekly-email';
import { update } from '@/routes/weekly-email/schedule';
import type { Team } from '@/types';
import { weekdays } from '../format';
import type { WeeklyEmailSettings } from '../types';

const props = defineProps<{
    settings: WeeklyEmailSettings;
    sendingProblem: string | null;
    subscribers: number;
    estimatedMinutes: number;
    dailyEmailLimit: number;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Email schedule',
                href: scheduleRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

let form = useForm({
    enabled: props.settings.enabled,
    send_day: Number(props.settings.send_day),
    send_time: props.settings.send_time.slice(0, 5),
});

let duration = computed(() => {
    if (props.subscribers <= 1) {
        return 'goes out right away';
    }

    const minutes = Math.max(props.estimatedMinutes, 1);

    return `goes to ${props.subscribers} people one at a time, a few seconds apart, and takes about ${minutes} minute${minutes === 1 ? '' : 's'}`;
});

let summary = computed(
    () =>
        `Every ${weekdays[form.send_day]} at ${form.send_time} (${page.props.currentTeam?.timezone ?? 'America/New_York'}), the email ${duration.value}.`,
);

let overLimit = computed(() => props.subscribers > props.dailyEmailLimit);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const submit = () => {
    form.submit(update(teamSlug.value), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
};
</script>

<template>
    <Head title="Email schedule" />

    <div class="flex max-w-2xl flex-col space-y-8 p-4">
        <Heading
            title="Email schedule"
            description="When the weekly email goes out"
        />

        <form class="space-y-6" @submit.prevent="submit">
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
            <label
                class="flex items-start justify-between gap-4 rounded-lg border p-4"
            >
                <span>
                    <span class="block font-medium">Send weekly</span>
                    <span class="block text-sm text-muted-foreground">
                        When off, no runs are created. Recipients and the
                        template are kept.
                    </span>
                </span>
                <Checkbox
                    :model-value="form.enabled"
                    @update:model-value="
                        (value: boolean | 'indeterminate') =>
                            (form.enabled = value === true)
                    "
                />
            </label>
            <InputError :message="form.errors.enabled" />

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="send_day">Day</Label>
                    <select
                        id="send_day"
                        v-model.number="form.send_day"
                        :class="selectClass"
                    >
                        <option
                            v-for="(day, index) in weekdays"
                            :key="day"
                            :value="index"
                        >
                            {{ day }}
                        </option>
                    </select>
                    <InputError :message="form.errors.send_day" />
                </div>

                <div class="grid gap-2">
                    <Label for="send_time">Send time</Label>
                    <Input
                        id="send_time"
                        v-model="form.send_time"
                        type="time"
                        required
                    />
                    <InputError :message="form.errors.send_time" />
                </div>

                <CongregationTimezone
                    usage="The send time is in this time zone."
                />
            </div>

            <p class="rounded-lg bg-muted/40 p-4 text-sm">
                <template v-if="form.enabled">{{ summary }}</template>
                <template v-else>Weekly sending is off.</template>
            </p>

            <p
                v-if="overLimit"
                class="rounded-lg border border-red-100 bg-red-50 p-4 text-sm text-red-700 dark:border-red-200/10 dark:bg-red-700/10 dark:text-red-100"
            >
                The list has {{ subscribers }} subscribers, but the
                congregation's MailChannels plan allows {{ dailyEmailLimit }}
                emails a day. The weekly email will not go out until the limit
                is raised in the congregation settings.
            </p>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing">
                    Save schedule
                </Button>
                <span v-if="form.isDirty" class="text-sm text-muted-foreground">
                    Unsaved changes
                </span>
            </div>
        </form>
    </div>
</template>
