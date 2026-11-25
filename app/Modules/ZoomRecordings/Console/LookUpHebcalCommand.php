<?php

namespace App\Modules\ZoomRecordings\Console;

use App\Modules\ZoomRecordings\Actions\LookUpHebcal;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Console\Command;

class LookUpHebcalCommand extends Command
{
    protected $signature = 'zoom-recordings:hebcal
        {--all : Look up recordings that already have Hebcal data too}';

    protected $description = 'Look up recording dates on Hebcal and prefill service and Torah portion where blank.';

    public function handle(LookUpHebcal $lookUp): int
    {
        $recordings = ZoomRecording::query()
            ->with('team')
            ->whereNotNull('recorded_at')
            ->when(! $this->option('all'), fn ($query) => $query->whereNull('hebcal'))
            ->orderBy('id')
            ->get();

        foreach ($recordings as $recording) {
            $lookUp->handle($recording);
            $recording->refresh();
            $this->line(sprintf('#%d %s · %s', $recording->id, $recording->serviceTitle(), $recording->torah_portion ?? 'no portion'));
        }

        $this->info($recordings->count().' recording(s) looked up.');

        return self::SUCCESS;
    }
}
