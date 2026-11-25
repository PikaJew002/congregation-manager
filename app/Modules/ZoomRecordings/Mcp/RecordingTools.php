<?php

namespace App\Modules\ZoomRecordings\Mcp;

use App\Mcp\CongregationContext;
use App\Models\Team;
use App\Modules\ZoomRecordings\Actions\DeleteRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecordingSetting;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Shared by the recording tools: resolves the congregation, finds one of its
 * recordings, and describes a recording the same way everywhere.
 */
class RecordingTools
{
    public const MODULE = 'zoom-recordings';

    public function __construct(private CongregationContext $context) {}

    public function team(Request $request): Team|Response
    {
        return $this->context->resolve($request, self::MODULE);
    }

    public function find(Team $team, mixed $id): ZoomRecording|Response
    {
        $recording = is_numeric($id)
            ? ZoomRecording::query()->forTeam($team)->whereKey((int) $id)->first()
            : null;

        return $recording ?? Response::error('No recording with that id in this congregation. Use list-recordings to find it.');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ZoomRecording $recording, bool $withLog = false): array
    {
        $base = rtrim((string) ZoomRecordingSetting::forTeam($recording->team)->public_base_url, '/');
        $published = filled($recording->hls_path) && $base !== '';

        $data = [
            'id' => $recording->id,
            'title' => $recording->title,
            'date' => $recording->localDate()?->toDateString(),
            'service' => $recording->serviceLabel(),
            'service_title' => $recording->serviceTitle(),
            'speaker' => $recording->speaker,
            'torah_portion' => $recording->torah_portion,
            'duration_minutes' => $recording->duration_minutes,
            'state' => $this->state($recording),
            'status' => $recording->status,
            'archived_at' => $recording->archived_at?->toIso8601String(),
            'can_delete' => DeleteRecording::canDelete($recording),
            'failed_step' => $recording->failed_step,
            'error' => $recording->error,
            'hls' => $published ? $base.'/'.$recording->hls_path : null,
            'mp4' => $published ? $base.'/'.$recording->directory().'/source.mp4' : null,
            'poster' => $published ? $base.'/'.$recording->directory().'/poster.jpg' : null,
        ];

        if ($withLog) {
            // Newest first: the latest step is what someone checking on a recording wants.
            $data['activity'] = collect($recording->log ?? [])->take(-15)->reverse()->values()->all();
        }

        return $data;
    }

    /** One word for what the recording is doing: archived, failed, processing, or published. */
    public function state(ZoomRecording $recording): string
    {
        return match (true) {
            $recording->isArchived() => 'archived',
            $recording->status === 'failed' => 'failed',
            ! DeleteRecording::canDelete($recording) => 'processing',
            default => 'published',
        };
    }
}
