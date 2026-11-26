<?php

namespace App\Modules\WeeklyEmail\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailRecipient extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'email',
        'name',
        'status',
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
