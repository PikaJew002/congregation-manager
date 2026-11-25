<?php

namespace App\Modules\WeeklyEmail\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyEmailSetting extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'enabled',
        'subject',
        'body',
        'zoom_link',
        'from_address',
        'from_name',
        'reply_to',
        'send_day',
        'send_time',
        'window_minutes',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function forTeam(Team $team): self
    {
        $settings = static::query()->firstOrCreate(
            ['team_id' => $team->id],
            [
                'body' => "Hello {{name}},\n\nJoin us this week: {{zoom_link}}\n\nUnsubscribe: {{unsubscribe_url}}",
                'from_address' => config('mail.from.address'),
                'from_name' => $team->name,
            ],
        );

        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }
}
