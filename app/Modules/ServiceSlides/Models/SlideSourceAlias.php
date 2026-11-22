<?php

namespace App\Modules\ServiceSlides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $slide_source_id
 * @property string $alias
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SlideSourceAlias extends Model
{
    protected $fillable = ['slide_source_id', 'alias'];

    /**
     * @return BelongsTo<SlideSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SlideSource::class, 'slide_source_id');
    }
}
