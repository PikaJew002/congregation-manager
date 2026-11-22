<?php

namespace App\Modules\ServiceSlides\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $kind
 * @property string $title
 * @property string|null $author
 * @property string|null $ccli
 * @property string|null $notes
 * @property string $original_path
 * @property string $original_format
 * @property string $file_path
 * @property string $conversion_status
 * @property int $slide_count
 * @property list<string|null>|null $slide_lines
 * @property string|null $page_size
 * @property Carbon|null $file_updated_at
 * @property bool $archived
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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

    /**
     * @return HasMany<SlideSourceAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(SlideSourceAlias::class);
    }

    /**
     * @return HasMany<ServiceDeckEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(ServiceDeckEntry::class);
    }
}
