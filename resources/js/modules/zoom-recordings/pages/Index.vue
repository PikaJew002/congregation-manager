<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Pencil, Trash2 } from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { index, retry, settings, update } from '@/routes/zoom-recordings';
import type { Team } from '@/types';
import DeleteRecordingModal from '../components/DeleteRecordingModal.vue';
import type { RecordingStatus, RecordingStep, ZoomRecording } from '../types';

const props = defineProps<{
    recordings: ZoomRecording[];
    publicBaseUrl: string | null;
    timezone: string;
    defaultService: string;
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
    'rendition:360p': 'encode the 360p video',
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

// Archived recordings are kept in storage but left out of the website feed.
// They get their own view, newest archived first.
let view = ref<'current' | 'archived'>(
    new URLSearchParams(window.location.search).get('view') === 'archived'
        ? 'archived'
        : 'current',
);
let currentRecordings = computed(() =>
    props.recordings.filter((r) => r.archived_at === null),
);
let archivedRecordings = computed(() =>
    props.recordings
        .filter((r) => r.archived_at !== null)
        .sort((a, b) =>
            (b.archived_at ?? '').localeCompare(a.archived_at ?? ''),
        ),
);
let shownRecordings = computed(() =>
    view.value === 'archived'
        ? archivedRecordings.value
        : currentRecordings.value,
);

const showView = (next: 'current' | 'archived') => {
    view.value = next;
    const url = new URL(window.location.href);
    if (next === 'archived') {
        url.searchParams.set('view', 'archived');
    } else {
        url.searchParams.delete('view');
    }
    window.history.replaceState(window.history.state, '', url);
};

let deleting = ref<ZoomRecording | null>(null);
let deleteOpen = ref(false);

const confirmDelete = (recording: ZoomRecording) => {
    deleting.value = recording;
    deleteOpen.value = true;
};

const canDelete = (recording: ZoomRecording) =>
    !processingStatuses.includes(recording.status);
let editingId = ref<number | null>(null);
let editForm = ref({ title: '', service: '', speaker: '', torah_portion: '' });

/** "Nov 21, 2026" in the congregation's time zone, as on the website. */
const serviceDate = (recording: ZoomRecording) =>
    recording.recorded_at
        ? new Intl.DateTimeFormat('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              timeZone: props.timezone,
          }).format(new Date(recording.recorded_at))
        : '';

const serviceTitle = (recording: ZoomRecording, label?: string | null) => {
    const date = serviceDate(recording);
    const name = label?.trim() || props.defaultService;

    return date ? `${name} - ${date}` : name;
};

const details = (recording: ZoomRecording) =>
    [
        serviceTitle(recording, recording.service),
        recording.speaker,
        recording.torah_portion,
    ]
        .filter(Boolean)
        .join(' · ');

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
    editForm.value = {
        title: recording.title,
        service: recording.service ?? props.defaultService,
        speaker: recording.speaker ?? '',
        torah_portion: recording.torah_portion ?? '',
    };
};

type Changes = Partial<
    Pick<ZoomRecording, 'title' | 'service' | 'speaker' | 'torah_portion'>
> & { archived?: boolean };

const save = (recording: ZoomRecording, changes: Changes) => {
    router.visit(update([teamSlug.value, recording.id]), {
        data: {
            title: changes.title ?? recording.title,
            service:
                changes.service !== undefined
                    ? changes.service
                    : recording.service,
            speaker:
                changes.speaker !== undefined
                    ? changes.speaker
                    : recording.speaker,
            torah_portion:
                changes.torah_portion !== undefined
                    ? changes.torah_portion
                    : recording.torah_portion,
            archived: changes.archived ?? recording.archived_at !== null,
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
            v-if="recordings.length > 0"
            class="flex gap-2"
            role="group"
            aria-label="Which recordings to show"
        >
            <Button
                size="sm"
                :variant="view === 'current' ? 'default' : 'outline'"
                :aria-pressed="view === 'current'"
                @click="showView('current')"
            >
                Recordings ({{ currentRecordings.length }})
            </Button>
            <Button
                size="sm"
                :variant="view === 'archived' ? 'default' : 'outline'"
                :aria-pressed="view === 'archived'"
                @click="showView('archived')"
            >
                Archived ({{ archivedRecordings.length }})
            </Button>
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

        <div
            v-else-if="shownRecordings.length === 0"
            class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            {{
                view === 'archived'
                    ? 'No archived recordings. Archiving takes a recording off the website but keeps its files.'
                    : 'Every recording is archived.'
            }}
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="recording in shownRecordings"
                :key="recording.id"
                class="rounded-lg border p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1 space-y-1">
                        <form
                            v-if="editingId === recording.id"
                            class="grid max-w-xl gap-3"
                            @submit.prevent="save(recording, { ...editForm })"
                        >
                            <div class="grid gap-1.5">
                                <Label :for="`title-${recording.id}`">
                                    Title
                                </Label>
                                <Input
                                    :id="`title-${recording.id}`"
                                    v-model="editForm.title"
                                    v-focus
                                    required
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`service-${recording.id}`">
                                    Service
                                </Label>
                                <div class="flex items-center gap-2">
                                    <Input
                                        :id="`service-${recording.id}`"
                                        v-model="editForm.service"
                                        :placeholder="defaultService"
                                    />
                                    <span
                                        v-if="serviceDate(recording)"
                                        class="text-sm whitespace-nowrap text-muted-foreground"
                                    >
                                        - {{ serviceDate(recording) }}
                                    </span>
                                </div>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="grid gap-1.5">
                                    <Label :for="`speaker-${recording.id}`">
                                        Speaker
                                    </Label>
                                    <Input
                                        :id="`speaker-${recording.id}`"
                                        v-model="editForm.speaker"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label :for="`portion-${recording.id}`">
                                        Torah portion
                                    </Label>
                                    <Input
                                        :id="`portion-${recording.id}`"
                                        v-model="editForm.torah_portion"
                                    />
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button type="submit" size="sm">Save</Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="editingId = null"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                        <div v-else class="flex items-center gap-2">
                            <h3 class="truncate font-medium">
                                {{ recording.title }}
                            </h3>
                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-label="`Edit ${recording.title}`"
                                @click="startEditing(recording)"
                            >
                                <Pencil class="h-3.5 w-3.5" />
                            </Button>
                        </div>
                        <p v-if="editingId !== recording.id" class="text-sm">
                            {{ details(recording) }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ formatDateTime(recording.recorded_at) }}
                            <template v-if="recording.duration_minutes">
                                · {{ recording.duration_minutes }} min
                            </template>
                            <template v-if="recording.archived_at">
                                · Archived
                                {{ formatDateTime(recording.archived_at) }}
                            </template>
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <Badge v-if="recording.archived_at" variant="secondary">
                            Archived
                        </Badge>
                        <Badge
                            v-else
                            :variant="statusVariant(recording.status)"
                        >
                            {{ statusLabels[recording.status] }}
                        </Badge>
                        <Button
                            v-if="recording.archived_at"
                            size="sm"
                            variant="outline"
                            @click="save(recording, { archived: false })"
                        >
                            <ArchiveRestore class="h-3.5 w-3.5" />
                            Restore
                        </Button>
                        <Button
                            v-else
                            size="sm"
                            variant="outline"
                            @click="save(recording, { archived: true })"
                        >
                            <Archive class="h-3.5 w-3.5" />
                            Archive
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="!canDelete(recording)"
                            :title="
                                canDelete(recording)
                                    ? undefined
                                    : 'Can be deleted once processing finishes'
                            "
                            :aria-label="`Delete ${recording.title}`"
                            @click="confirmDelete(recording)"
                        >
                            <Trash2 class="h-3.5 w-3.5 text-destructive" />
                        </Button>
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

        <DeleteRecordingModal v-model:open="deleteOpen" :recording="deleting" />
    </div>
</template>
