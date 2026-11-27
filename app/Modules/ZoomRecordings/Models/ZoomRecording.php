<?php

namespace App\Modules\ZoomRecordings\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Support\HebrewCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $meeting_uuid
 * @property string|null $meeting_id
 * @property string $title
 * @property string|null $service
 * @property string|null $speaker
 * @property string|null $torah_portion
 * @property array<string, mixed>|null $hebcal
 * @property Carbon|null $recorded_at
 * @property int|null $duration_minutes
 * @property string|null $download_url
 * @property string|null $download_token
 * @property string|null $recording_file_id
 * @property string $status
 * @property string|null $failed_step
 * @property string|null $media_step
 * @property string|null $error
 * @property string|null $source_path
 * @property int|null $source_height
 * @property string|null $hls_path
 * @property Carbon|null $archived_at
 * @property list<array{at: string, message: string}>|null $log
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ZoomRecording extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'meeting_uuid',
        'meeting_id',
        'title',
        'service',
        'speaker',
        'torah_portion',
        'hebcal',
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
        'archived_at',
        'log',
    ];

    protected $hidden = [
        'download_url',
        'download_token',
        'hebcal',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'download_token' => 'encrypted',
            'archived_at' => 'datetime',
            'log' => 'array',
            'hebcal' => 'array',
        ];
    }

    public function note(string $message): void
    {
        $log = $this->log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'message' => $message];
        $this->update(['log' => $log]);
    }

    /** Archived recordings keep their files but are left out of the public feed. */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public const DEFAULT_SERVICE = HebrewCalendar::DEFAULT_SERVICE;

    /**
     * The recording's date where the congregation meets. Zoom reports UTC, so
     * an evening service in the Americas would otherwise land on the next day.
     */
    public function localDate(): ?CarbonImmutable
    {
        if ($this->recorded_at === null) {
            return null;
        }

        return CarbonImmutable::parse($this->recorded_at)->setTimezone($this->team->localTimezone());
    }

    public function serviceLabel(): string
    {
        return filled($this->service) ? (string) $this->service : self::DEFAULT_SERVICE;
    }

    /** "Shabbat Morning Service - Nov 21, 2026"; the date is always appended. */
    public function serviceTitle(): string
    {
        $date = $this->localDate();

        return $this->serviceLabel().($date ? ' - '.$date->format('M j, Y') : '');
    }

    public function directory(): string
    {
        $prefix = trim(ZoomRecordingSetting::forTeam($this->team)->r2_prefix, '/');

        return $prefix.'/'.$this->id;
    }
}
