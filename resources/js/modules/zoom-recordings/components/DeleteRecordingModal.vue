<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { destroy } from '@/routes/zoom-recordings';
import type { ZoomRecording } from '../types';

const props = defineProps<{
    recording: ZoomRecording | null;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const page = usePage();
const processing = ref(false);
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const deleteRecording = () => {
    if (!props.recording) {
        return;
    }

    router.visit(destroy([teamSlug.value, props.recording.id]), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: () => emit('update:open', false),
    });
};
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete recording</DialogTitle>
                <DialogDescription>
                    Permanently delete
                    <strong>{{ props.recording?.title }}</strong
                    >? Its video, streams, poster and the original Zoom file are
                    removed from storage and the website. This cannot be undone.
                </DialogDescription>
            </DialogHeader>

            <p class="text-sm text-muted-foreground">
                To take it off the website but keep the files, archive it
                instead.
            </p>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>

                <Button
                    data-test="delete-recording-confirm"
                    variant="destructive"
                    :disabled="processing"
                    @click="deleteRecording"
                >
                    Delete recording
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
