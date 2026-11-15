<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { schedule as scheduleRoute } from '@/routes/weekly-email';
import { update } from '@/routes/weekly-email/schedule';
import type { Team } from '@/types';
import { weekdays } from '../format';
import type { WeeklyEmailSettings } from '../types';

const props = defineProps<{
    settings: WeeklyEmailSettings;
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

const timezones = Intl.supportedValuesOf('timeZone');

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

let form = useForm({
    enabled: props.settings.enabled,
    send_day: Number(props.settings.send_day),
    send_time: props.settings.send_time.slice(0, 5),
    timezone: props.settings.timezone,
    window_minutes: Number(props.settings.window_minutes),
});

let timezoneOptions = computed(() =>
    timezones.includes(form.timezone)
        ? timezones
        : [form.timezone, ...timezones],
);

let windowLabel = computed(() => {
    const minutes = Number(form.window_minutes) || 0;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return [hours ? `${hours}h` : '', rest ? `${rest}m` : '']
        .filter(Boolean)
        .join(' ');
});

let summary = computed(
    () =>
        `Every ${weekdays[form.send_day]}, sending starts at ${form.send_time} (${form.timezone}) and is spread over ${windowLabel.value || '0m'}.`,
);

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
                    <Label for="send_time">Start time</Label>
                    <Input
                        id="send_time"
                        v-model="form.send_time"
                        type="time"
                        required
                    />
                    <InputError :message="form.errors.send_time" />
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">Time zone</Label>
                    <select
                        id="timezone"
                        v-model="form.timezone"
                        :class="selectClass"
                    >
                        <option
                            v-for="zone in timezoneOptions"
                            :key="zone"
                            :value="zone"
                        >
                            {{ zone }}
                        </option>
                    </select>
                    <InputError :message="form.errors.timezone" />
                </div>

                <div class="grid gap-2">
                    <Label for="window_minutes">Send window (minutes)</Label>
                    <Input
                        id="window_minutes"
                        v-model.number="form.window_minutes"
                        type="number"
                        min="30"
                        max="240"
                        step="5"
                        required
                    />
                    <p class="text-xs text-muted-foreground">
                        30 to 240. Emails are spaced out across this window.
                    </p>
                    <InputError :message="form.errors.window_minutes" />
                </div>
            </div>

            <p class="rounded-lg bg-muted/40 p-4 text-sm">
                <template v-if="form.enabled">{{ summary }}</template>
                <template v-else>Weekly sending is off.</template>
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
