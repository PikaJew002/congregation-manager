<?php

namespace App\Modules\ServiceSlides\Actions;

use App\Models\Team;
use App\Modules\ServiceSlides\Models\Service;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Service slides card on the dashboard: whether the next service's decks
 * are ready, the services after it, and decks that need someone to look.
 */
class SlidesDashboard
{
    private const UPCOMING = 4;

    /**
     * @return array<string, mixed> The card, shaped as ProvidesDashboard describes.
     */
    public function handle(Team $team): array
    {
        $today = $team->localNow()->toDateString();

        /** @var Collection<int, Service> $upcoming */
        $upcoming = Service::query()->forTeam($team)
            ->where('service_date', '>=', $today)
            ->orderBy('service_date')
            ->limit(self::UPCOMING)
            ->with('decks')
            ->get();

        $next = $upcoming->first();

        $attention = $upcoming->flatMap(fn (Service $service) => $service->decks
            ->map(fn (ServiceDeck $deck) => $this->problem($deck, $service, $team))
            ->filter())
            ->sortBy(fn (array $item) => $item['tone'] === 'danger' ? 0 : 1)
            ->take(6)
            ->values()
            ->all();

        $recentDecks = ServiceDeck::query()
            ->whereNotNull('generated_at')
            ->whereHas('service', fn ($query) => $query->where('team_id', $team->id))
            ->with('service')
            ->latest('generated_at')
            ->limit(5)
            ->get();

        return [
            'headline' => $next
                ? [
                    'title' => $next->title,
                    'detail' => 'Next service · '.$this->date($next),
                    'status' => $this->readiness($next),
                ]
                : [
                    'title' => 'No upcoming services',
                    'detail' => 'Add one or import from the calendar in Services.',
                    'status' => null,
                ],
            'stats' => [
                ['label' => 'Decks ready', 'value' => $next ? $this->readyCount($next).' of '.$next->decks->count() : '—'],
                ['label' => 'Library items', 'value' => (string) SlideSource::query()->forTeam($team)->where('archived', false)->count()],
                ['label' => 'Templates', 'value' => (string) SlideTemplate::query()->forTeam($team)->count()],
            ],
            'attention' => $attention,
            'activity' => $recentDecks->map(fn (ServiceDeck $deck) => [
                'at' => $deck->generated_at->toIso8601String(),
                'title' => 'Deck generated',
                'detail' => $deck->name.' · '.$deck->service->title,
                'href' => $this->serviceUrl($deck->service, $team),
            ])->values()->all(),
            'upcoming' => [
                'title' => 'Upcoming services',
                'items' => $upcoming->map(fn (Service $service) => [
                    'title' => $service->title,
                    'detail' => $this->date($service),
                    'href' => $this->serviceUrl($service, $team),
                    'status' => $this->readiness($service),
                ])->values()->all(),
            ],
        ];
    }

    private function isReady(ServiceDeck $deck): bool
    {
        return $deck->status === 'ready' && ! $deck->stale && ! $deck->blocked;
    }

    private function readyCount(Service $service): int
    {
        return $service->decks->filter(fn (ServiceDeck $deck) => $this->isReady($deck))->count();
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function readiness(Service $service): array
    {
        $total = $service->decks->count();
        $ready = $this->readyCount($service);

        return match (true) {
            $total === 0 => ['label' => 'No decks', 'tone' => 'warning'],
            $service->decks->contains(fn (ServiceDeck $deck) => $deck->blocked || $deck->status === 'failed') => ['label' => 'Needs fixing', 'tone' => 'danger'],
            $ready === $total => ['label' => 'Ready', 'tone' => 'success'],
            default => ['label' => "{$ready} of {$total} ready", 'tone' => 'warning'],
        };
    }

    /**
     * @return array{title: string, detail: string, href: string, tone: string}|null
     */
    private function problem(ServiceDeck $deck, Service $service, Team $team): ?array
    {
        [$detail, $tone] = match (true) {
            $deck->blocked => ['A slide it uses is missing from its template or library file.', 'danger'],
            $deck->status === 'failed' => ['Generating it failed. '.collect($deck->warnings)->first(), 'danger'],
            $deck->status === 'generating' => [null, null],
            $deck->generated_path === null => ['Not generated yet.', 'warning'],
            $deck->stale || $deck->status === 'stale' => ['Changed since it was generated.', 'warning'],
            default => [null, null],
        };

        if ($detail === null) {
            return null;
        }

        return [
            'title' => $deck->name.' · '.$service->title.' ('.$this->date($service).')',
            'detail' => trim($detail),
            'href' => $this->serviceUrl($service, $team),
            'tone' => $tone,
        ];
    }

    private function date(Service $service): string
    {
        return CarbonImmutable::parse($service->service_date)->format('D, M j');
    }

    private function serviceUrl(Service $service, Team $team): string
    {
        return route('service-slides.services.show', [$team->slug, $service]);
    }
}
