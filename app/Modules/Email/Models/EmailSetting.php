<?php

namespace App\Modules\Email\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What every email of a congregation shares: who it comes from, and the
 * variables any email can use, such as the Zoom link.
 *
 * @property int $id
 * @property int $team_id
 * @property string $from_address
 * @property string $from_name
 * @property string|null $reply_to
 * @property list<array{key: string, value: string}>|null $variables
 */
class EmailSetting extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'from_address',
        'from_name',
        'reply_to',
        'variables',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The shared variables as key => value.
     *
     * @return array<string, string>
     */
    public function variableMap(): array
    {
        return Email::mapVariables($this->variables);
    }

    public static function forTeam(Team $team): self
    {
        $settings = static::query()->firstOrCreate(
            ['team_id' => $team->id],
            [
                'from_address' => config('mail.from.address'),
                'from_name' => $team->name,
                'variables' => [],
            ],
        );

        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }
}
