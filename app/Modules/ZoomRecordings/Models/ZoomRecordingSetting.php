<?php

namespace App\Modules\ZoomRecordings\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

class ZoomRecordingSetting extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'webhook_secret',
        'r2_prefix',
        'public_base_url',
        'preferred_recording_type',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
        ];
    }

    public static function forTeam(Team $team): self
    {
        $settings = static::query()->firstOrCreate(
            ['team_id' => $team->id],
            ['public_base_url' => config('filesystems.disks.r2-public.url')],
        );

        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }
}
