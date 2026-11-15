<?php

namespace App\Modules\ServiceSlides\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SlideTemplate::class, 'slide_template_id');
    }

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

    public function scopeUsingSource(Builder $query, int $sourceId): void
    {
        $query->whereHas('entries', fn (Builder $entries) => $entries->where('slide_source_id', $sourceId));
    }
}
