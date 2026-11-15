<?php

namespace App\Modules\ServiceSlides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlideSourceAlias extends Model
{
    protected $fillable = ['slide_source_id', 'alias'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(SlideSource::class, 'slide_source_id');
    }
}
