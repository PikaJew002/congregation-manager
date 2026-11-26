<?php

namespace App\Modules\WeeklyEmail\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property string $email
 * @property string|null $name
 * @property string $status
 * @property string|null $status_reason
 * @property int $soft_bounce_count
 * @property string $unsubscribe_token
 * @property CarbonImmutable|null $unsubscribed_at
 */
class EmailRecipient extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'email',
        'name',
        'status',
        'status_reason',
        'soft_bounce_count',
        'unsubscribe_token',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'unsubscribed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EmailRecipient $recipient) {
            $recipient->unsubscribe_token ??= Str::random(64);
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function unsubscribeUrl(): string
    {
        return route('weekly-email.unsubscribe', $this->unsubscribe_token);
    }
}
