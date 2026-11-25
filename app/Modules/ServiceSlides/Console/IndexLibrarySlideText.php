<?php

namespace App\Modules\ServiceSlides\Console;

use App\Modules\ServiceSlides\Actions\SlideFiles;
use App\Modules\ServiceSlides\Models\SlideSource;
use Illuminate\Console\Command;
use Throwable;

class IndexLibrarySlideText extends Command
{
    protected $signature = 'service-slides:index-slide-text {--all : Read every item again, not only those without slide text}';

    protected $description = 'Read the slide text of library items so they can be found by the words on their slides';

    public function handle(SlideFiles $files): int
    {
        $failed = 0;
        $indexed = 0;

        // Paged by id: filling an item takes it out of the whereNull filter, which would make offset pages skip rows.
        $sources = SlideSource::query()
            ->unless($this->option('all'), fn ($query) => $query->whereNull('search_text'))
            ->lazyById();

        foreach ($sources as $source) {
            try {
                $files->indexSlideText($source);
                $indexed++;
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error("{$source->title} ({$source->id}): {$exception->getMessage()}");
            }
        }

        $this->components->info("Read the slide text of {$indexed} library ".str('item')->plural($indexed).'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
