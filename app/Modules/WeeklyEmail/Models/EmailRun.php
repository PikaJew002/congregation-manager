<?php

namespace App\Modules\WeeklyEmail\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property Carbon $scheduled_for
 * @property string $status
 * @property string $subject
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $deliveries_count
 * @property-read int|null $sent_count
 * @property-read int|null $failed_count
 */
class EmailRun extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'scheduled_for',
        'status',
        'subject',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EmailDelivery::class);
    }
}
