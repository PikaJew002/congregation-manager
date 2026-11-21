<?php

namespace App\Modules\ServiceSlides\Console;

use App\Modules\ServiceSlides\Actions\SlideFiles;
use App\Modules\ServiceSlides\Models\SlideSource;
use Illuminate\Console\Command;
use Throwable;

class FormatLibrarySongs extends Command
{
    protected $signature = 'service-slides:format-songs';

    protected $description = 'Rebuild every library song from its uploaded file with the current slide layout fixes';

    public function handle(SlideFiles $files): int
    {
        $failed = 0;

        SlideSource::query()->where('kind', 'song')->orderBy('id')->each(function (SlideSource $source) use ($files, &$failed) {
            try {
                $notes = $files->reformatSource($source);
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error("{$source->title}: {$exception->getMessage()}");

                return;
            }

            $this->components->twoColumnDetail($source->title, $notes === [] ? 'formatted' : implode(' ', $notes));
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
