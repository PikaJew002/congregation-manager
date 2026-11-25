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

export type RecordingStep =
    | 'download'
    | 'transcode'
    | 'rendition:360p'
    | 'rendition:480p'
    | 'rendition:720p'
    | 'rendition:1080p'
    | 'poster'
    | 'manifest';

export type ZoomRecording = {
    id: number;
    meeting_id: string | null;
    title: string;
    service: string | null;
    speaker: string | null;
    torah_portion: string | null;
    recorded_at: string | null;
    duration_minutes: number | null;
    status: RecordingStatus;
    failed_step: RecordingStep | null;
    error: string | null;
    hls_path: string | null;
    archived_at: string | null;
    log: Array<{ at: string; message: string }> | null;
    created_at: string;
};

export type ZoomRecordingSettings = {
    r2_prefix: string;
    public_base_url: string | null;
    preferred_recording_type: string;
    timezone: string;
    has_webhook_secret: boolean;
    webhook_url: string;
};
