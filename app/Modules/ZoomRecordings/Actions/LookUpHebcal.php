<?php

namespace App\Modules\ZoomRecordings\Actions;

use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Looks up the recording's local date on Hebcal, keeps the response on the
 * recording, and prefills the service name and Torah portion. Fields someone
 * already filled in are left alone.
 */
class LookUpHebcal
{
    public const URL = 'https://www.hebcal.com/hebcal';

    /**
     * Hebcal files these under "major" too, but they do not replace the
     * Shabbat service name here.
     */
    private const NOT_A_SERVICE = ['Chanukah', 'Purim', 'Erev Purim'];

    public function handle(ZoomRecording $recording): void
    {
        $date = $recording->localDate();
        if ($date === null) {
            return;
        }

        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(10)->get(self::URL, [
                'v' => 1,
                'cfg' => 'json',
                'start' => $date->toDateString(),
                'end' => $date->toDateString(),
                'maj' => 'on',
                's' => 'on',
                'i' => 'off',
                'min' => 'off',
                'mod' => 'off',
                'nx' => 'off',
                'mf' => 'off',
                'ss' => 'off',
            ])->throw();
        } catch (Throwable $exception) {
            $recording->note('Could not look up the Hebrew calendar: '.Str::limit($exception->getMessage(), 200));

            return;
        }

        $hebcal = (array) $response->json();
        $items = (array) ($hebcal['items'] ?? []);

        $recording->update(array_filter([
            'hebcal' => $hebcal,
            'service' => blank($recording->service) ? self::service($items) : null,
            'torah_portion' => blank($recording->torah_portion) ? self::portion($items) : null,
        ], fn ($value) => $value !== null));
        $recording->note('Looked up '.$date->toDateString().' on the Hebrew calendar.');

        if (filled($recording->hls_path) && ! $recording->isArchived()) {
            (new UpdateManifest($recording->id))->handle();
        }
    }

    /**
     * "Erev Rosh Hashana Service", "Yom Kippur Morning Service", or the
     * default Shabbat service when no major holiday falls on the date.
     *
     * @param  array<int, mixed>  $items
     */
    public static function service(array $items): string
    {
        foreach ($items as $item) {
            if (($item['category'] ?? null) !== 'holiday' || ($item['subcat'] ?? null) !== 'major') {
                continue;
            }

            $name = self::holidayName((string) ($item['title'] ?? ''));
            if ($name === '' || in_array($name, self::NOT_A_SERVICE, true)) {
                continue;
            }

            return str_starts_with($name, 'Erev ') ? $name.' Service' : $name.' Morning Service';
        }

        return ZoomRecording::DEFAULT_SERVICE;
    }

    /**
     * "Vayetzei" from "Parashat Vayetzei", or null when no portion is read.
     *
     * @param  array<int, mixed>  $items
     */
    public static function portion(array $items): ?string
    {
        foreach ($items as $item) {
            if (($item['category'] ?? null) === 'parashat') {
                return trim((string) preg_replace('/^Parashat\s+/', '', (string) ($item['title'] ?? ''))) ?: null;
            }
        }

        return null;
    }

    /** "Rosh Hashana 5787" -> "Rosh Hashana", "Chanukah: 2 Candles" -> "Chanukah". */
    private static function holidayName(string $title): string
    {
        $title = (string) preg_replace('/:.*$/', '', $title);

        return trim((string) preg_replace('/\s+\d{4}$/', '', $title));
    }
}
