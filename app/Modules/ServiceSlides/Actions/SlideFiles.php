<?php

namespace App\Modules\ServiceSlides\Actions;

use App\Models\Team;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\OdpInspector;
use App\Modules\ServiceSlides\Support\Preset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SlideFiles
{
    public function __construct(private OdpInspector $inspector, private Preset $preset) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createSource(Team $team, UploadedFile $file, array $attributes): SlideSource
    {
        return SlideSource::query()->create([
            ...$attributes,
            ...$this->sourceFileAttributes($team, $file),
            'team_id' => $team->id,
            'original_format' => 'odp',
            'conversion_status' => 'ready',
        ]);
    }

    public function replaceSource(SlideSource $source, UploadedFile $file): void
    {
        $old = $source->odp_path;
        $source->update($this->sourceFileAttributes($source->team, $file));
        $this->deleteIfUnused($old, $source->odp_path);

        ServiceDeck::query()->usingSource($source->id)->each(fn (ServiceDeck $deck) => $deck->markStale());
    }

    public function setArchived(SlideSource $source, bool $archived): void
    {
        $source->update(['archived' => $archived]);

        ServiceDeck::query()->usingSource($source->id)->each(fn (ServiceDeck $deck) => $deck->markStale());
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
        $old = $template->odp_path;

        DB::transaction(function () use ($template, $file) {
            $template->update($this->templateFileAttributes($template->team, $file));
            $this->syncTemplateSlides($template, $file->getRealPath());
        });

        $this->deleteIfUnused($old, $template->odp_path);
        $template->decks()->each(fn (ServiceDeck $deck) => $deck->markStale());
    }

    /**
     * Keeps existing labels and roles by slide position. Slides past the end of the new file stay,
     * flagged as missing, so deck entries that use them can be shown and fixed.
     */
    private function syncTemplateSlides(SlideTemplate $template, string $path): void
    {
        $slides = $this->inspector->slides($path);
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

    /**
     * @return array<string, mixed>
     */
    private function sourceFileAttributes(Team $team, UploadedFile $file): array
    {
        $local = $file->getRealPath();
        $slides = $this->inspector->usedSlides($this->inspector->slides($local));
        $path = $file->store('slides/'.$team->id, 'files');

        return [
            'original_path' => $path,
            'odp_path' => $path,
            'slide_count' => count($slides),
            'slide_lines' => array_map(fn (array $slide) => $slide['line'], $slides),
            'page_size' => $this->inspector->pageSize($local),
            'file_updated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateFileAttributes(Team $team, UploadedFile $file): array
    {
        return [
            'odp_path' => $file->store('templates/'.$team->id, 'files'),
            'page_size' => $this->inspector->pageSize($file->getRealPath()),
            'file_updated_at' => now(),
        ];
    }

    private function deleteIfUnused(?string $old, string $new): void
    {
        if ($old && $old !== $new) {
            Storage::disk('files')->delete($old);
        }
    }
}
