<?php

namespace App\Modules\ServiceSlides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $slide_template_id
 * @property int $slide_index
 * @property string $label
 * @property string $role
 * @property bool $missing
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SlideTemplateSlide extends Model
{
    protected $fillable = ['slide_template_id', 'slide_index', 'label', 'role', 'missing'];

    protected function casts(): array
    {
        return ['missing' => 'boolean'];
    }

    /**
     * @return BelongsTo<SlideTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SlideTemplate::class, 'slide_template_id');
    }
}
