<?php

namespace App\Modules\Email\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $email_id
 * @property Carbon $scheduled_for
 * @property string $status
 * @property string|null $pause_reason
 * @property Carbon|null $paused_at
 * @property string $subject
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Email|null $email
 * @property-read int|null $deliveries_count
 * @property-read int|null $sent_count
 * @property-read int|null $failed_count
 * @property-read int|null $retryable_count
 */
class EmailRun extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'email_id',
        'scheduled_for',
        'status',
        'pause_reason',
        'paused_at',
        'subject',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'paused_at' => 'datetime',
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
     * The email this run sent. Null once that email has been deleted.
     *
     * @return BelongsTo<Email, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * @return HasMany<EmailDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(EmailDelivery::class);
    }
}
