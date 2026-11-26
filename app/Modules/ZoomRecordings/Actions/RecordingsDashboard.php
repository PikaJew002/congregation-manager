<?php

namespace App\Modules\ZoomRecordings\Actions;

use App\Models\Team;
use App\Modules\ZoomRecordings\Models\ZoomRecording;

/**
 * The Recordings card on the dashboard: the latest published recording, what
 * is still processing, and anything that failed.
 */
class RecordingsDashboard
{
    public const PUBLISHED = ['completed', 'manifested'];

    private const STEP_LABELS = [
        'download' => 'downloading from Zoom',
        'transcode' => 'transcoding',
        'rendition:360p' => 'encoding the 360p video',
        'rendition:480p' => 'encoding the 480p video',
        'rendition:720p' => 'encoding the 720p video',
        'rendition:1080p' => 'encoding the 1080p video',
        'poster' => 'grabbing the poster image',
        'manifest' => 'updating the website list',
    ];

    /**
     * @return array<string, mixed> The card, shaped as ProvidesDashboard describes.
     */
    public function handle(Team $team): array
    {
        $href = route('zoom-recordings.index', $team->slug);
        $recordings = fn () => ZoomRecording::query()->forTeam($team)->with('team');

        $latest = $recordings()
            ->whereIn('status', self::PUBLISHED)
            ->whereNull('archived_at')
            ->latest('recorded_at')
            ->first();

        $published = $recordings()->whereIn('status', self::PUBLISHED)->whereNull('archived_at')->count();
        $failed = $recordings()->where('status', 'failed')->latest('recorded_at')->get();
        $processing = $recordings()->whereNotIn('status', [...self::PUBLISHED, 'failed'])->count();

        return [
            'headline' => $latest
                ? [
                    'title' => $latest->serviceTitle(),
                    'detail' => collect([$latest->speaker, $latest->torah_portion])->filter()->implode(' · ') ?: null,
                    'status' => ['label' => 'Latest on the website', 'tone' => 'success'],
                ]
                : [
                    'title' => 'No recordings yet',
                    'detail' => 'Recordings appear here after Zoom sends them.',
                    'status' => null,
                ],
            'stats' => [
                ['label' => 'Published', 'value' => (string) $published],
                ['label' => 'Processing', 'value' => (string) $processing, 'tone' => $processing > 0 ? 'warning' : 'muted'],
                ['label' => 'Failed', 'value' => (string) $failed->count(), 'tone' => $failed->isNotEmpty() ? 'danger' : 'muted'],
            ],
            'attention' => $failed->take(5)->map(fn (ZoomRecording $recording) => [
                'title' => 'Recording failed: '.$recording->serviceTitle(),
                'detail' => $recording->failed_step
                    ? 'Stopped while '.(self::STEP_LABELS[$recording->failed_step] ?? $recording->failed_step).'. Retry it from Recordings.'
                    : 'Retry it from Recordings.',
                'href' => $href,
                'tone' => 'danger',
            ])->values()->all(),
            'activity' => $recordings()->latest('updated_at')->limit(5)->get()->map(fn (ZoomRecording $recording) => [
                'at' => $recording->updated_at->toIso8601String(),
                'title' => match (true) {
                    in_array($recording->status, self::PUBLISHED, true) && $recording->isArchived() => 'Recording archived',
                    in_array($recording->status, self::PUBLISHED, true) => 'Recording published',
                    $recording->status === 'failed' => 'Recording failed',
                    default => 'Recording processing',
                },
                'detail' => $recording->serviceTitle(),
                'href' => $href,
            ])->values()->all(),
        ];
    }
}
