export type RecordingStatus =
    | 'received'
    | 'downloading'
    | 'downloaded'
    | 'transcoding'
    | 'uploading'
    | 'uploaded'
    | 'manifested'
    | 'completed'
    | 'failed';

export type RecordingStep = 'download' | 'transcode' | 'manifest';

export type ZoomRecording = {
    id: number;
    meeting_id: string | null;
    title: string;
    recorded_at: string | null;
    duration_minutes: number | null;
    status: RecordingStatus;
    failed_step: RecordingStep | null;
    error: string | null;
    hls_path: string | null;
    hidden: boolean;
    log: Array<{ at: string; message: string }> | null;
    created_at: string;
};

export type ZoomRecordingSettings = {
    r2_prefix: string;
    public_base_url: string | null;
    preferred_recording_type: string;
    has_webhook_secret: boolean;
    webhook_url: string;
};
