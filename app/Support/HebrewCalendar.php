<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Hebcal lookups shared by every module: what a congregation's service on a
 * given day is called, and which Torah portion is read.
 */
class HebrewCalendar
{
    public const URL = 'https://www.hebcal.com/hebcal';

    /** Most services; used whenever no major holiday falls on the day. */
    public const DEFAULT_SERVICE = 'Shabbat Morning Service';

    /**
     * Hebcal files these under "major" too, but they do not replace the
     * Shabbat service name.
     */
    private const NOT_A_SERVICE = ['Chanukah', 'Purim', 'Erev Purim'];

    /**
     * Hebcal's full response for one day (Y-m-d): major holidays and the
     * Torah portion, in the diaspora calendar.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function day(string $date): array
    {
        return (array) Http::acceptJson()->connectTimeout(5)->timeout(10)->get(self::URL, [
            'v' => 1,
            'cfg' => 'json',
            'start' => $date,
            'end' => $date,
            'maj' => 'on',
            's' => 'on',
            'i' => 'off',
            'min' => 'off',
            'mod' => 'off',
            'nx' => 'off',
            'mf' => 'off',
            'ss' => 'off',
        ])->throw()->json();
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

        return self::DEFAULT_SERVICE;
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
