<?php

namespace App\Modules\ServiceSlides\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use BelongsToTeam;

    protected $fillable = ['team_id', 'service_date', 'title', 'notes', 'source'];

    protected function casts(): array
    {
        return ['service_date' => 'date:Y-m-d'];
    }

    public function decks(): HasMany
    {
        return $this->hasMany(ServiceDeck::class)->orderBy('position');
    }
}
