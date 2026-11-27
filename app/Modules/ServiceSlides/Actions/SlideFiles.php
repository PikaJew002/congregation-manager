<?php

namespace App\Modules\ServiceSlides\Actions;

use App\Models\Team;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\Preset;
use App\Modules\ServiceSlides\Support\SearchText;
use App\Modules\ServiceSlides\Support\SlideFormats;
use App\Modules\ServiceSlides\Support\SongSlideFormatter;
use App\Support\LocalFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SlideFiles
{
    public function __construct(private SlideFormats $formats, private Preset $preset, private SongSlideFormatter $formatter) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createSource(Team $team, UploadedFile $file, array $attributes): SlideSource
    {
        return SlideSource::query()->create([
            ...$attributes,
            ...$this->sourceFileAttributes($team, $file, $attributes['kind'] ?? 'song'),
            'team_id' => $team->id,
            'conversion_status' => 'ready',
        ]);
    }

    public function replaceSource(SlideSource $source, UploadedFile $file): void
    {
        $old = [$source->original_path, $source->file_path];
        $source->update($this->sourceFileAttributes($source->team, $file, $source->kind));
        foreach (array_unique($old) as $path) {
            $this->deleteIfUnused($path, $source->original_path, $source->file_path);
        }

        $this->markDecksStale($source);
    }

    /**
     * Rebuilds the file decks use from the uploaded original, so song layout fixes reach items added before them
     * and follow a change of kind.
     *
     * @return list<string>
     */
    public function reformatSource(SlideSource $source): array
    {
        $old = $source->file_path;
        $notes = [];
        $path = $source->original_path;

        if ($this->formatsAsSong($source->kind, $source->original_format)) {
            $local = tempnam(sys_get_temp_dir(), 'library-odp-');
            file_put_contents($local, Storage::disk('files')->get($source->original_path));
            try {
                [$path, $notes] = $this->formatSong($source->team_id, $local, $source->original_path);
            } finally {
                @unlink($local);
            }
        }

        $source->update(['file_path' => $path]);
        $this->deleteIfUnused($old, $source->original_path, $path);
        $this->markDecksStale($source);

        return $notes;
    }

    public function setArchived(SlideSource $source, bool $archived): void
    {
        $source->update(['archived' => $archived]);

        $this->markDecksStale($source);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTemplate(Team $team, UploadedFile $file, array $attributes): SlideTemplate
    {
        return DB::transaction(function () use ($team, $file, $attributes) {
            $template = SlideTemplate::query()->create([
                ...$attributes,
                ...$this->templateFileAttributes($team, $file),
                'team_id' => $team->id,
            ]);

            $this->syncTemplateSlides($template, $file->getRealPath());
            $template->update(['preset' => $this->preset->suggest($template)]);

            return $template;
        });
    }

    public function replaceTemplate(SlideTemplate $template, UploadedFile $file): void
    {
        $old = $template->file_path;

        DB::transaction(function () use ($template, $file) {
            $template->update($this->templateFileAttributes($template->team, $file));
            $this->syncTemplateSlides($template, $file->getRealPath());
        });

        $this->deleteIfUnused($old, $template->file_path);
        $template->decks()->each(fn (ServiceDeck $deck) => $deck->markStale());
    }

    /**
     * Keeps existing labels and roles by slide position. Slides past the end of the new file stay,
     * flagged as missing, so deck entries that use them can be shown and fixed.
     */
    private function syncTemplateSlides(SlideTemplate $template, string $path): void
    {
        $slides = $this->formats->inspector($template->format)->slides($path);
        $existing = $template->slides()->get()->keyBy('slide_index');

        foreach ($slides as $slide) {
            $row = $existing->get($slide['index']);

            if ($row) {
                $row->update(['missing' => false]);

                continue;
            }

            $template->slides()->create([
                'slide_index' => $slide['index'],
                'label' => $slide['label'],
                'role' => $slide['empty'] ? 'separator' : ($slide['index'] === 0 ? 'intro' : 'title'),
            ]);
        }

        $template->slides()->where('slide_index', '>=', count($slides))->update(['missing' => true]);
    }

    /** Stores an upload on the files disk under a random name, or throws if it cannot. */
    private function store(UploadedFile $file, string $directory, string $format): string
    {
        $path = $file->storeAs($directory, Str::random(40).'.'.$format, 'files');

        if ($path === false) {
            throw new RuntimeException("Unable to store {$file->getClientOriginalName()}.");
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceFileAttributes(Team $team, UploadedFile $file, string $kind): array
    {
        $local = $file->getRealPath();
        $format = $this->formats->detect($local) ?? 'odp';
        $inspector = $this->formats->inspector($format);
        $slides = $inspector->usedSlides($inspector->slides($local));
        $original = $this->store($file, 'slides/'.$team->id, $format);

        return [
            'original_path' => $original,
            'original_format' => $format,
            'file_path' => $this->formatsAsSong($kind, $format) ? $this->formatSong($team->id, $local, $original)[0] : $original,
            'slide_count' => count($slides),
            'slide_lines' => array_map(fn (array $slide) => $slide['line'], $slides),
            ...$this->slideTextAttributes($slides),
            'page_size' => $inspector->pageSize($local),
            'file_updated_at' => now(),
        ];
    }

    /**
     * Every line of the slides decks use, and the same text normalized for library search.
     *
     * @param  list<array{lines: list<string>}>  $slides
     * @return array{slide_text: list<list<string>>, search_text: string}
     */
    public function slideTextAttributes(array $slides): array
    {
        $text = array_map(fn (array $slide) => $slide['lines'], $slides);

        return ['slide_text' => $text, 'search_text' => SearchText::forSlides($text)];
    }

    /**
     * Reads the slide text of an item added before content search, from the file decks are built from.
     */
    public function indexSlideText(SlideSource $source): void
    {
        $local = tempnam(sys_get_temp_dir(), 'library-text-');
        file_put_contents($local, Storage::disk('files')->get($source->file_path));

        try {
            $inspector = $this->formats->inspector($this->formats->detect($local) ?? $source->original_format);
            $source->update($this->slideTextAttributes($inspector->usedSlides($inspector->slides($local))));
        } finally {
            @unlink($local);
        }
    }

    private function formatsAsSong(string $kind, ?string $format): bool
    {
        return $kind === 'song' && ($format ?? 'odp') === 'odp';
    }

    /**
     * Stores a formatted copy of the song, or falls back to the original when the file cannot be formatted.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function formatSong(int $teamId, string $local, string $original): array
    {
        $formatted = tempnam(sys_get_temp_dir(), 'library-song-');

        try {
            $notes = $this->formatter->format($local, $formatted);
            $path = 'slides/'.$teamId.'/'.Str::random(40).'.odp';
            Storage::disk('files')->put($path, LocalFile::read($formatted));

            return [$path, $notes];
        } catch (Throwable $exception) {
            Log::warning('Song slides could not be formatted.', ['path' => $original, 'error' => $exception->getMessage()]);

            return [$original, ['The file could not be formatted, so it is used as uploaded.']];
        } finally {
            @unlink($formatted);
        }
    }

    private function markDecksStale(SlideSource $source): void
    {
        ServiceDeck::query()->usingSource($source->id)->each(fn (ServiceDeck $deck) => $deck->markStale());
    }

    /**
     * @return array<string, mixed>
     */
    private function templateFileAttributes(Team $team, UploadedFile $file): array
    {
        $format = $this->formats->detect($file->getRealPath()) ?? 'odp';

        return [
            'file_path' => $this->store($file, 'templates/'.$team->id, $format),
            'format' => $format,
            'page_size' => $this->formats->inspector($format)->pageSize($file->getRealPath()),
            'file_updated_at' => now(),
        ];
    }

    private function deleteIfUnused(?string $old, string ...$current): void
    {
        if ($old && ! in_array($old, $current, true)) {
            Storage::disk('files')->delete($old);
        }
    }
}
