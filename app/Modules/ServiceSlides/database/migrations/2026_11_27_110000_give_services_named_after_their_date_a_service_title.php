<?php

use App\Modules\ServiceSlides\Jobs\NameServiceFromCalendar;
use App\Support\HebrewCalendar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Services created without a title used to be titled with their date. They
 * get the default title instead, then the holiday name where the date is a
 * major holiday (left as the default if the calendar cannot be reached).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('services')->get(['id', 'title', 'service_date'])
            ->filter(fn (object $service) => trim((string) $service->title) === substr((string) $service->service_date, 0, 10))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::table('services')->where('id', $id)->update(['title' => HebrewCalendar::DEFAULT_SERVICE]);
            NameServiceFromCalendar::dispatchSync($id);
        }
    }

    public function down(): void
    {
        // The old titles were only the date, which the list already shows.
    }
};
