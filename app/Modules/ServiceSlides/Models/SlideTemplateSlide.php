<?php

namespace App\Modules\ServiceSlides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlideTemplateSlide extends Model
{
    protected $fillable = ['slide_template_id', 'slide_index', 'label', 'role', 'missing'];

    protected function casts(): array
    {
        return ['missing' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SlideTemplate::class, 'slide_template_id');
    }
}
