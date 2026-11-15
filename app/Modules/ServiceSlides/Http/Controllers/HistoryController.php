<?php

namespace App\Modules\ServiceSlides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\ServiceSlides\Models\Service;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\ServiceDeckEntry;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function __invoke(Team $current_team): Response
    {
        $entries = ServiceDeckEntry::query()
            ->where('entry_type', 'source')
            ->whereNotNull('slide_source_id')
            ->whereHas('deck.template', fn ($query) => $query->where('is_history_tracked', true)->where('team_id', $current_team->id))
            ->with('source:id,title,kind,archived', 'deck.service:id,service_date')
            ->get();

        $items = $entries->groupBy('slide_source_id')->map(function ($group) {
            $source = $group->first()->source;

            if (! $source) {
                return null;
            }

            $dates = $group->map(fn ($entry) => $entry->deck->service->service_date->toDateString())->unique()->sort()->values();

            return [
                'id' => $source->id,
                'title' => $source->title,
                'kind' => $source->kind,
                'archived' => $source->archived,
                'count' => $dates->count(),
                'first_used' => $dates->first(),
                'last_used' => $dates->last(),
            ];
        })->filter()->sortByDesc('count')->values();

        $services = Service::query()->forTeam($current_team)
            ->whereHas('decks.template', fn ($query) => $query->where('is_history_tracked', true))
            ->with([
                'decks' => fn ($query) => $query->whereHas('template', fn ($template) => $template->where('is_history_tracked', true)),
                'decks.entries.source:id,title',
            ])
            ->orderByDesc('service_date')
            ->get()
            ->map(fn (Service $service) => [
                'id' => $service->id,
                'title' => $service->title,
                'service_date' => $service->service_date->toDateString(),
                'decks' => $service->decks->map(fn (ServiceDeck $deck) => [
                    'name' => $deck->name,
                    'items' => $deck->entries->whereNotNull('source')->pluck('source.title')->values(),
                ]),
            ]);

        return Inertia::render('service-slides/History', [
            'items' => $items,
            'services' => $services,
        ]);
    }
}
