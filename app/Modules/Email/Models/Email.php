<?php

namespace App\Modules\Email\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One email a congregation sends: either recurring (on some weekdays at a
 * time) or one-time (at send_at). Each send becomes an EmailRun holding the
 * finished text.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $status draft, scheduled or sent
 * @property string $send_mode recurring or once
 * @property string $subject
 * @property string $body
 * @property list<array{key: string, value: string}>|null $variables
 * @property list<int>|null $send_days
 * @property string|null $send_time
 * @property CarbonImmutable|null $send_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Team|null $team
 */
class Email extends Model
{
    use BelongsToTeam;

    public const RECURRING = 'recurring';

    public const ONCE = 'once';

    protected $fillable = [
        'team_id',
        'name',
        'status',
        'send_mode',
        'subject',
        'body',
        'variables',
        'send_days',
        'send_time',
        'send_at',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'send_days' => 'array',
            'send_at' => 'immutable_datetime',
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
     * @return HasMany<EmailRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(EmailRun::class);
    }

    public function isRecurring(): bool
    {
        return $this->send_mode === self::RECURRING;
    }

    /**
     * Weekdays it goes out on, 0 (Sunday) to 6, in order.
     *
     * @return list<int>
     */
    public function sendDays(): array
    {
        $days = array_values(array_unique(array_map('intval', $this->send_days ?? [])));
        sort($days);

        return $days;
    }

    /**
     * The next time this email goes out by its own settings, in the
     * congregation's time zone, whether or not it is switched on. Null when it
     * has no day or time to go by.
     */
    public function nextSendAt(Team $team, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $localNow = ($now ?? CarbonImmutable::now())->setTimezone($team->localTimezone());

        if (! $this->isRecurring()) {
            return $this->send_at?->setTimezone($team->localTimezone());
        }

        $days = $this->sendDays();

        if ($days === [] || blank($this->send_time)) {
            return null;
        }

        for ($ahead = 0; $ahead <= 7; $ahead++) {
            $candidate = $localNow->addDays($ahead)->setTimeFromTimeString($this->send_time);

            if (in_array($candidate->dayOfWeek, $days, true) && $candidate->gte($localNow)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * This email's own variables as key => value.
     *
     * @return array<string, string>
     */
    public function variableMap(): array
    {
        return self::mapVariables($this->variables);
    }

    /**
     * A stored list of {key, value} as key => value. The list comes from a
     * JSON column, so rows without a usable name are skipped.
     *
     * @param  array<array-key, mixed>|null  $variables
     * @return array<string, string>
     */
    public static function mapVariables(?array $variables): array
    {
        $map = [];

        foreach ($variables ?? [] as $variable) {
            $key = is_array($variable) ? ($variable['key'] ?? null) : null;

            if (is_string($key) && $key !== '') {
                $value = $variable['value'] ?? '';
                $map[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $map;
    }
}
