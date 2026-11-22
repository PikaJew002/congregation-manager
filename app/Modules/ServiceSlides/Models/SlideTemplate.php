<?php

namespace App\Modules\ServiceSlides\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $file_path
 * @property string $format
 * @property string|null $page_size
 * @property string|null $default_kind
 * @property bool $is_history_tracked
 * @property list<array<string, mixed>>|null $preset
 * @property Carbon|null $file_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SlideTemplate extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id', 'name', 'file_path', 'format', 'page_size', 'default_kind', 'is_history_tracked', 'preset', 'file_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_history_tracked' => 'boolean',
            'preset' => 'array',
            'file_updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SlideTemplateSlide, $this>
     */
    public function slides(): HasMany
    {
        return $this->hasMany(SlideTemplateSlide::class)->orderBy('slide_index');
    }

    /**
     * @return HasMany<ServiceDeck, $this>
     */
    public function decks(): HasMany
    {
        return $this->hasMany(ServiceDeck::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function slots(): array
    {
        return array_values(array_filter($this->preset ?? [], fn (array $step) => ($step['type'] ?? null) === 'slot'));
    }
}
