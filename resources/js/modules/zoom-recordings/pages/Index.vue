<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { EyeOff, Pencil } from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import { index, retry, settings, update } from '@/routes/zoom-recordings';
import type { Team } from '@/types';
import type { RecordingStatus, RecordingStep, ZoomRecording } from '../types';

const props = defineProps<{
    recordings: ZoomRecording[];
    publicBaseUrl: string | null;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Recordings',
                href: index(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

const statusLabels: Record<RecordingStatus, string> = {
    received: 'Queued',
    downloading: 'Downloading',
    downloaded: 'Downloaded',
    transcoding: 'Transcoding',
    uploading: 'Uploading',
    uploaded: 'Uploaded',
    manifested: 'Published',
    completed: 'Published',
    failed: 'Failed',
};

const stepLabels: Record<RecordingStep, string> = {
    download: 'download from Zoom',
    transcode: 'transcode and upload',
    'rendition:480p': 'encode the 480p video',
    'rendition:720p': 'encode the 720p video',
    'rendition:1080p': 'encode the 1080p video',
    poster: 'grab the poster image',
    manifest: 'update the website list',
};

const processingStatuses: RecordingStatus[] = [
    'received',
    'downloading',
    'downloaded',
    'transcoding',
    'uploading',
    'uploaded',
];

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
let editingId = ref<number | null>(null);
let editTitle = ref('');

let anyProcessing = computed(() =>
    props.recordings.some((r) => processingStatuses.includes(r.status)),
);

let pollTimer: ReturnType<typeof setInterval> | null = null;

const stopPolling = () => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

watch(
    anyProcessing,
    (processing) => {
        stopPolling();

        if (processing) {
            pollTimer = setInterval(
                () => router.reload({ only: ['recordings'] }),
                10000,
            );
        }
    },
    { immediate: true },
);

onUnmounted(stopPolling);

const statusVariant = (
    status: RecordingStatus,
): 'default' | 'secondary' | 'destructive' => {
    if (status === 'failed') {
        return 'destructive';
    }

    return status === 'manifested' || status === 'completed'
        ? 'default'
        : 'secondary';
};

const playlistUrl = (recording: ZoomRecording) =>
    props.publicBaseUrl && recording.hls_path
        ? `${props.publicBaseUrl.replace(/\/+$/, '')}/${recording.hls_path}`
        : null;

const startEditing = (recording: ZoomRecording) => {
    editingId.value = recording.id;
    editTitle.value = recording.title;
};

const save = (recording: ZoomRecording, changes: Partial<ZoomRecording>) => {
    router.visit(update([teamSlug.value, recording.id]), {
        data: {
            title: changes.title ?? recording.title,
            hidden: changes.hidden ?? recording.hidden,
        },
        preserveScroll: true,
        onSuccess: () => (editingId.value = null),
    });
};

const retryRecording = (recording: ZoomRecording) => {
    router.visit(retry([teamSlug.value, recording.id]), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Recordings" />

    <div class="flex flex-col space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Recordings"
                description="Zoom cloud recordings are downloaded, converted for streaming, and published to the website automatically."
            />
            <span v-if="anyProcessing" class="text-sm text-muted-foreground">
                Refreshing while recordings process…
            </span>
        </div>

        <div
            v-if="recordings.length === 0"
            class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            No recordings yet. Once the Zoom webhook is set up in
            <Link
                :href="settings(teamSlug)"
                class="underline underline-offset-4"
            >
                Recording settings</Link
            >, new cloud recordings show up here.
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="recording in recordings"
                :key="recording.id"
                class="rounded-lg border p-4"
                :class="{ 'opacity-70': recording.hidden }"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1 space-y-1">
                        <form
                            v-if="editingId === recording.id"
                            class="flex max-w-xl gap-2"
                            @submit.prevent="
                                save(recording, { title: editTitle })
                            "
                        >
                            <Input v-model="editTitle" v-focus required />
                            <Button type="submit" size="sm">Save</Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="editingId = null"
                            >
                                Cancel
                            </Button>
                        </form>
                        <div v-else class="flex items-center gap-2">
                            <h3 class="truncate font-medium">
                                {{ recording.title }}
                            </h3>
                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-label="`Rename ${recording.title}`"
                                @click="startEditing(recording)"
                            >
                                <Pencil class="h-3.5 w-3.5" />
                            </Button>
                            <EyeOff
                                v-if="recording.hidden"
                                class="h-4 w-4 text-muted-foreground"
                                aria-label="Hidden from website"
                            />
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ formatDateTime(recording.recorded_at) }}
                            <template v-if="recording.duration_minutes">
                                · {{ recording.duration_minutes }} min
                            </template>
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <Badge :variant="statusVariant(recording.status)">
                            {{ statusLabels[recording.status] }}
                        </Badge>
                        <label class="flex items-center gap-2 text-sm">
                            <Checkbox
                                :model-value="!recording.hidden"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') =>
                                        save(recording, {
                                            hidden: value !== true,
                                        })
                                "
                            />
                            Show on website
                        </label>
                    </div>
                </div>

                <div
                    v-if="recording.status === 'failed'"
                    class="mt-3 flex flex-wrap items-start justify-between gap-3 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm"
                >
                    <div class="min-w-0">
                        <p class="font-medium text-destructive">
                            Failed to
                            {{
                                recording.failed_step
                                    ? (stepLabels[recording.failed_step] ??
                                      recording.failed_step)
                                    : 'process'
                            }}
                        </p>
                        <p
                            v-if="recording.error"
                            class="mt-1 break-words text-muted-foreground"
                        >
                            {{ recording.error }}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="retryRecording(recording)"
                    >
                        Retry
                    </Button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
                    <a
                        v-if="playlistUrl(recording)"
                        :href="playlistUrl(recording) ?? undefined"
                        target="_blank"
                        rel="noopener"
                        class="text-muted-foreground underline underline-offset-4 hover:text-foreground"
                    >
                        HLS playlist
                    </a>
                    <details
                        v-if="recording.log && recording.log.length"
                        class="text-muted-foreground"
                    >
                        <summary class="cursor-pointer hover:text-foreground">
                            Activity ({{ recording.log.length }})
                        </summary>
                        <ul class="mt-2 space-y-1">
                            <li
                                v-for="(entry, i) in recording.log"
                                :key="i"
                                class="flex gap-3"
                            >
                                <span class="whitespace-nowrap tabular-nums">
                                    {{ formatDateTime(entry.at) }}
                                </span>
                                <span>{{ entry.message }}</span>
                            </li>
                        </ul>
                    </details>
                </div>
            </div>
        </div>
    </div>
</template>
