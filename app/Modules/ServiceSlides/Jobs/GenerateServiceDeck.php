<?php

namespace App\Modules\ServiceSlides\Jobs;

use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\ServiceDeckEntry;
use App\Modules\ServiceSlides\Support\SlideFormats;
use App\Support\LocalFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateServiceDeck implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $deckId) {}

    public function handle(SlideFormats $formats): void
    {
        $deck = ServiceDeck::query()->with(['template', 'entries.templateSlide', 'entries.source', 'service'])->findOrFail($this->deckId);

        if ($deck->entries->contains(fn (ServiceDeckEntry $entry) => $entry->isBroken())) {
            $deck->update([
                'status' => 'failed',
                'blocked' => true,
                'warnings' => ['Some entries point at template slides that are no longer in the template file. Remove or replace them, then generate again.'],
            ]);

            return;
        }

        $format = $deck->template->format;
        $mismatched = $deck->entries
            ->filter(fn (ServiceDeckEntry $entry) => $entry->source && $entry->source->original_format !== $format)
            ->map(fn (ServiceDeckEntry $entry) => $entry->source->title)
            ->unique();

        if ($mismatched->isNotEmpty()) {
            $deck->update([
                'status' => 'failed',
                'warnings' => ['This deck uses '.SlideFormats::LABELS[$format].' files, so these items cannot go in it: '.$mismatched->implode(', ').'.'],
            ]);

            return;
        }

        $deck->update(['status' => 'generating']);
        $work = storage_path('app/deck-'.$deck->id);
        if (! is_dir($work)) {
            mkdir($work, 0755, true);
        }

        try {
            $templatePath = $work.'/template.'.$format;
            file_put_contents($templatePath, Storage::disk('files')->get($deck->template->file_path));
            $entries = [];

            foreach ($deck->entries as $entry) {
                if ($entry->entry_type === 'template_slide' && $entry->templateSlide) {
                    $entries[] = ['type' => 'template_slide', 'index' => $entry->templateSlide->slide_index];
                }

                if ($entry->entry_type === 'source' && $entry->source) {
                    $sourcePath = $work.'/source-'.$entry->id.'.'.$format;
                    file_put_contents($sourcePath, Storage::disk('files')->get($entry->source->file_path));
                    $entries[] = ['type' => 'source', 'path' => $sourcePath, 'label' => $entry->source->title];
                }
            }

            $output = $work.'/deck.'.$format;
            $warnings = $formats->builder($format)->build($templatePath, $entries, $output);
            $stored = 'decks/'.$deck->service->team_id.'/'.$deck->id.'.'.$format;
            Storage::disk('files')->put($stored, LocalFile::read($output));
            $changed = $deck->fresh()->status !== 'generating';
            $deck->update([
                'generated_path' => $stored,
                'generated_at' => now(),
                'status' => $changed ? 'stale' : 'ready',
                'stale' => $changed,
                'blocked' => false,
                'warnings' => $warnings,
            ]);
        } catch (Throwable $exception) {
            $deck->update(['status' => 'failed', 'warnings' => [$exception->getMessage()]]);
            throw $exception;
        } finally {
            foreach (glob($work.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($work);
        }
    }
}
