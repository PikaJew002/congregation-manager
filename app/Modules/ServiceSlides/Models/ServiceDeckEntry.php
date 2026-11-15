<?php

namespace App\Modules\ServiceSlides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceDeckEntry extends Model
{
    protected $fillable = [
        'service_deck_id', 'position', 'entry_type', 'slide_template_slide_id', 'slide_source_id', 'slot_key', 'auto',
    ];

    protected function casts(): array
    {
        return ['auto' => 'boolean'];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(ServiceDeck::class, 'service_deck_id');
    }

    public function templateSlide(): BelongsTo
    {
        return $this->belongsTo(SlideTemplateSlide::class, 'slide_template_slide_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SlideSource::class, 'slide_source_id');
    }

    public function isBroken(): bool
    {
        return $this->entry_type === 'template_slide'
            ? ! $this->templateSlide || $this->templateSlide->missing
            : ! $this->source;
    }
}
