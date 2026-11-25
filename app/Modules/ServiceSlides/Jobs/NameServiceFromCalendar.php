<?php

namespace App\Modules\ServiceSlides\Jobs;

use App\Modules\ServiceSlides\Models\Service;
use App\Support\HebrewCalendar;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Renames a new service for a holiday on its date ("Erev Rosh Hashana
 * Service"). Runs after the response (afterResponse), and only while the
 * service still has the default title, so a title someone typed is kept.
 */
class NameServiceFromCalendar
{
    use Dispatchable;

    public function __construct(public int $serviceId) {}

    public function handle(HebrewCalendar $calendar): void
    {
        $service = Service::query()->find($this->serviceId);

        if ($service === null || $service->title !== HebrewCalendar::DEFAULT_SERVICE) {
            return;
        }

        try {
            $items = (array) ($calendar->day($service->service_date->toDateString())['items'] ?? []);
        } catch (Throwable) {
            // The default title stands; it is right for most services.
            return;
        }

        $name = HebrewCalendar::service($items);

        if ($name !== $service->title) {
            $service->update(['title' => $name]);
        }
    }
}
