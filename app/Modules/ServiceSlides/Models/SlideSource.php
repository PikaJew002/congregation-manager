<?php

namespace App\Modules\ServiceSlides\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlideSource extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id', 'kind', 'title', 'author', 'ccli', 'notes', 'original_path', 'original_format',
        'file_path', 'conversion_status', 'slide_count', 'slide_lines', 'page_size', 'file_updated_at', 'archived',
    ];

    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
            'slide_lines' => 'array',
            'file_updated_at' => 'datetime',
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(SlideSourceAlias::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ServiceDeckEntry::class);
    }
}
