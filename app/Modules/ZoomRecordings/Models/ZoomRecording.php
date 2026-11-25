<?php

namespace App\Modules\ZoomRecordings\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;

class ZoomRecording extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'meeting_uuid',
        'meeting_id',
        'title',
        'recorded_at',
        'duration_minutes',
        'download_url',
        'download_token',
        'recording_file_id',
        'status',
        'failed_step',
        'error',
        'source_path',
        'source_height',
        'media_step',
        'hls_path',
        'hidden',
        'log',
    ];

    protected $hidden = [
        'download_url',
        'download_token',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'download_token' => 'encrypted',
            'hidden' => 'boolean',
            'log' => 'array',
        ];
    }

    public function note(string $message): void
    {
        $log = $this->log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'message' => $message];
        $this->update(['log' => $log]);
    }

    public function directory(): string
    {
        $prefix = trim(ZoomRecordingSetting::forTeam($this->team)->r2_prefix, '/');

        return $prefix.'/'.$this->id;
    }
}
