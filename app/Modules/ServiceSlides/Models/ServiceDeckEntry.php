<?php

namespace App\Modules\ServiceSlides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $service_deck_id
 * @property int $position
 * @property string $entry_type
 * @property int|null $slide_template_slide_id
 * @property int|null $slide_source_id
 * @property string|null $slot_key
 * @property bool $auto
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceDeck $deck
 * @property-read SlideTemplateSlide|null $templateSlide
 * @property-read SlideSource|null $source
 */
class ServiceDeckEntry extends Model
{
    protected $fillable = [
        'service_deck_id', 'position', 'entry_type', 'slide_template_slide_id', 'slide_source_id', 'slot_key', 'auto',
    ];

    protected function casts(): array
    {
        return ['auto' => 'boolean'];
    }

    /**
     * @return BelongsTo<ServiceDeck, $this>
     */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(ServiceDeck::class, 'service_deck_id');
    }

    /**
     * @return BelongsTo<SlideTemplateSlide, $this>
     */
    public function templateSlide(): BelongsTo
    {
        return $this->belongsTo(SlideTemplateSlide::class, 'slide_template_slide_id');
    }

    /**
     * @return BelongsTo<SlideSource, $this>
     */
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
