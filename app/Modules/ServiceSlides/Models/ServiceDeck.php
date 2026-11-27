<?php

namespace App\Modules\ServiceSlides\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $service_id
 * @property int $slide_template_id
 * @property string $name
 * @property int $position
 * @property string|null $generated_path
 * @property Carbon|null $generated_at
 * @property string $status
 * @property list<string>|null $warnings
 * @property bool $stale
 * @property bool $blocked
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Service $service
 * @property-read SlideTemplate $template
 * @property-read Collection<int, ServiceDeckEntry> $entries
 */
class ServiceDeck extends Model
{
    protected $fillable = [
        'service_id', 'slide_template_id', 'name', 'position', 'generated_path',
        'generated_at', 'status', 'warnings', 'stale', 'blocked',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'warnings' => 'array',
            'stale' => 'boolean',
            'blocked' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<SlideTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SlideTemplate::class, 'slide_template_id');
    }

    /**
     * @return HasMany<ServiceDeckEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(ServiceDeckEntry::class)->orderBy('position');
    }

    public function markStale(): void
    {
        $this->update([
            'stale' => true,
            'status' => $this->generated_path ? 'stale' : 'draft',
            'blocked' => $this->entries()->with('templateSlide', 'source')->get()->contains(fn (ServiceDeckEntry $entry) => $entry->isBroken()),
        ]);
    }

    /**
     * @param  Builder<ServiceDeck>  $query
     */
    public function scopeUsingSource(Builder $query, int $sourceId): void
    {
        $query->whereHas('entries', fn (Builder $entries) => $entries->where('slide_source_id', $sourceId));
    }
}
