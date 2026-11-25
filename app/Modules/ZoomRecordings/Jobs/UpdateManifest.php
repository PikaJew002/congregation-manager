<?php

namespace App\Modules\ZoomRecordings\Jobs;

use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecordingSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class UpdateManifest implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $recordingId)
    {
        $this->onConnection('database-media');
    }

    public function handle(): void
    {
        $recording = ZoomRecording::query()->with('team')->findOrFail($this->recordingId);
        $settings = ZoomRecordingSetting::forTeam($recording->team);
        $prefix = trim($settings->r2_prefix, '/');
        $disk = Storage::disk('r2-public');
        $path = $prefix.'/recordings.json';
        $existing = $disk->exists($path) ? json_decode($disk->get($path), true) : ['recordings' => []];
        $base = rtrim((string) $settings->public_base_url, '/');
        $items = collect($existing['recordings'] ?? [])->reject(fn ($item) => ($item['id'] ?? null) === $recording->id)->values();

        if (! $recording->hidden) {
            $items->prepend([
                'id' => $recording->id,
                'title' => $recording->title,
                'date' => $recording->localDate()?->toDateString(),
                'service' => $recording->serviceLabel(),
                'service_title' => $recording->serviceTitle(),
                'speaker' => $recording->speaker ?: null,
                'torah_portion' => $recording->torah_portion ?: null,
                'duration' => $recording->duration_minutes,
                'hls' => $base.'/'.$recording->hls_path,
                'mp4' => $base.'/'.$recording->directory().'/source.mp4',
                'poster' => $base.'/'.$recording->directory().'/poster.jpg',
            ]);
        }

        // Newest recording date first, so editing an older recording does not
        // move it to the top. Same date: the later recording (higher id) first.
        // Undated entries go last.
        $items = $items->sort(fn (array $a, array $b) => [$b['date'] ?? '', $b['id'] ?? 0] <=> [$a['date'] ?? '', $a['id'] ?? 0])->values();

        $disk->put($path, json_encode(['recordings' => $items->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), [
            'ContentType' => 'application/json',
            'CacheControl' => 'public, max-age=60',
        ]);

        $recording->update(['status' => 'completed']);
        $recording->note('Updated recordings manifest.');
    }
}
