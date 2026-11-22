<?php

namespace App\Modules\ServiceSlides\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Team;
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
     * An active item with this title and kind, ignoring one row when it is being edited or restored.
     */
    public static function activeDuplicate(Team $team, string $title, string $kind, ?int $exceptId = null): ?self
    {
        return static::query()
            ->forTeam($team)
            ->where('title', $title)
            ->where('kind', $kind)
            ->where('archived', false)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->first();
    }

    public static function duplicateMessage(string $title, string $kind): string
    {
        $label = strtolower((string) (config('modules.slide_kinds')[$kind] ?? $kind));

        return $title.' is already in this congregation\'s library as '.$label.'.';
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
