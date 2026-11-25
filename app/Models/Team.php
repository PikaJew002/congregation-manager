<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property bool $is_personal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 */
#[Fillable(['name', 'slug', 'timezone', 'is_personal', 'uuid'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    public const DEFAULT_TIMEZONE = 'America/New_York';

    /** @var array<string, mixed> */
    protected $attributes = ['timezone' => self::DEFAULT_TIMEZONE];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->uuid)) {
                $team->uuid = (string) Str::uuid();
            }

            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * @return HasMany<TeamModule, $this>
     */
    public function moduleSettings(): HasMany
    {
        return $this->hasMany(TeamModule::class);
    }

    /**
     * @return HasMany<TeamModuleUser, $this>
     */
    public function moduleGrants(): HasMany
    {
        return $this->hasMany(TeamModuleUser::class);
    }

    /** The congregation's time zone, where its services happen. */
    public function localTimezone(): string
    {
        return $this->timezone ?: self::DEFAULT_TIMEZONE;
    }

    /** The current moment in the congregation's time zone. */
    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->localTimezone());
    }

    /**
     * A calendar date (Y-m-d) from what someone typed or an MCP client sent. A
     * plain date is taken as it is; a moment with a time is read in the
     * congregation's time zone, so 2026-11-28T03:00:00Z is Nov 27 in New York.
     */
    public function localDate(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        return CarbonImmutable::parse($value, $this->localTimezone())->setTimezone($this->localTimezone())->toDateString();
    }

    public function privilegedMemberCount(): int
    {
        return $this->memberships()
            ->whereIn('role', [TeamRole::Owner->value, TeamRole::SuperAdmin->value])
            ->count();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
