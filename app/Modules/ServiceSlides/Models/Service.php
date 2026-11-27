<?php

namespace App\Modules\ServiceSlides\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property Carbon $service_date
 * @property string $title
 * @property string|null $notes
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ServiceDeck> $decks
 */
class Service extends Model
{
    use BelongsToTeam;

    protected $fillable = ['team_id', 'service_date', 'title', 'notes', 'source'];

    protected function casts(): array
    {
        return ['service_date' => 'date:Y-m-d'];
    }

    /**
     * @return HasMany<ServiceDeck, $this>
     */
    public function decks(): HasMany
    {
        return $this->hasMany(ServiceDeck::class)->orderBy('position');
    }
}
