<?php

namespace App\Modules\ServiceSlides\Actions;

use App\Models\Team;
use App\Modules\ServiceSlides\Jobs\NameServiceFromCalendar;
use App\Modules\ServiceSlides\Models\Service;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\Preset;
use App\Support\HebrewCalendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ServicePlanner
{
    /** @var array<int, Collection<int, SlideSource>> */
    private array $library = [];

    public function __construct(private Preset $preset) {}

    /**
     * The congregation's service on a date (a day in its time zone), created
     * if needed. A new service without a title gets the default Shabbat title
     * and is renamed afterwards when the date is a major holiday. An existing
     * service is returned as it is.
     */
    public function service(Team $team, string $date, ?string $title = null, string $source = 'manual'): Service
    {
        $title = filled($title) ? trim($title) : null;

        $service = Service::query()->firstOrCreate(
            ['team_id' => $team->id, 'service_date' => $team->localDate($date)],
            ['title' => $title ?? HebrewCalendar::DEFAULT_SERVICE, 'source' => $source],
        );

        if ($service->wasRecentlyCreated && $title === null) {
            NameServiceFromCalendar::dispatch($service->id)->afterResponse();
        }

        return $service;
    }

    public function createDeck(Service $service, SlideTemplate $template): ServiceDeck
    {
        $deck = $service->decks()->create([
            'slide_template_id' => $template->id,
            'name' => $template->name,
            'position' => $service->decks()->count(),
        ]);

        $this->replaceEntries($deck, $this->presetEntries($template));

        return $deck;
    }

    public function resetDeck(ServiceDeck $deck): void
    {
        $this->replaceEntries($deck, $this->presetEntries($deck->template));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presetEntries(SlideTemplate $template): array
    {
        $entries = [];

        foreach ($this->preset->steps($template) as $step) {
            if ($step['type'] === 'template_slide') {
                $entries[] = [
                    'entry_type' => 'template_slide',
                    'slide_template_slide_id' => $step['slide_id'],
                    'slide_source_id' => null,
                    'slot_key' => null,
                    'auto' => false,
                ];

                continue;
            }

            $defaults = SlideSource::query()->forTeam($template->team)
                ->whereIn('id', $step['default_source_ids'] ?? [])
                ->where('original_format', $template->format)
                ->where('archived', false)
                ->get()
                ->sortBy(fn (SlideSource $source) => array_search($source->id, $step['default_source_ids'], true));

            array_push($entries, ...$this->preset->slotEntries($step, $defaults));
        }

        return $entries;
    }

    /**
     * @param  array<string, list<string>>  $slots
     * @return array{unmatched: array<string, list<string>>, notes: list<string>}
     *
     * @throws InvalidArgumentException
     */
    public function fillSlots(ServiceDeck $deck, array $slots): array
    {
        $deck->loadMissing('template', 'service');
        $steps = $this->preset->steps($deck->template);
        $slotSteps = collect($steps)->where('type', 'slot')->keyBy('key');

        foreach (array_keys($slots) as $key) {
            if (! $slotSteps->has($key)) {
                $known = $slotSteps->keys()->implode(', ') ?: 'none';

                throw new InvalidArgumentException("The {$deck->template->name} template has no slot \"{$key}\". Its slots are: {$known}.");
            }
        }

        $entries = $this->currentEntries($deck);
        $unmatched = [];
        $notes = [];

        foreach ($slots as $key => $titles) {
            $slot = $slotSteps[$key];
            $sources = [];

            foreach ($titles as $title) {
                $title = trim($title);
                if ($title === '') {
                    continue;
                }

                $match = $this->match($deck->service->team, $title, $deck->template->format, $slot['kind'] ?? null);

                if ($match) {
                    $sources[] = $match;
                } else {
                    $unmatched[$key][] = $title;
                }
            }

            if (! $slot['repeat'] && count($sources) > 1) {
                $ignored = collect($sources)->slice(1)->pluck('title')->implode(', ');
                $notes[] = "The {$slot['label']} slot takes one item, so these were left out: {$ignored}.";
            }

            $entries = $this->replaceSlot($entries, $steps, $slot, $this->preset->slotEntries($slot, $sources));
        }

        $this->replaceEntries($deck, $entries);

        return ['unmatched' => $unmatched, 'notes' => $notes];
    }

    /**
     * @param  list<string>  $titles
     * @return array{unmatched: array<string, list<string>>, notes: list<string>}
     *
     * @throws InvalidArgumentException
     */
    public function fill(ServiceDeck $deck, array $titles): array
    {
        $deck->loadMissing('template');
        $repeating = collect($this->preset->steps($deck->template))
            ->where('type', 'slot')
            ->where('repeat', true)
            ->pluck('key');

        if ($repeating->count() !== 1) {
            $keys = collect($deck->template->slots())->pluck('key')->implode(', ') ?: 'none';

            throw new InvalidArgumentException("The {$deck->template->name} template needs titles per slot. Its slots are: {$keys}.");
        }

        return $this->fillSlots($deck, [$repeating->first() => $titles]);
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $entries  Request input may not be a list; positions follow its order.
     */
    public function replaceEntries(ServiceDeck $deck, array $entries): void
    {
        DB::transaction(function () use ($deck, $entries) {
            $deck->entries()->delete();

            foreach (array_values($entries) as $position => $entry) {
                $deck->entries()->create([
                    'position' => $position,
                    'entry_type' => $entry['entry_type'],
                    'slide_template_slide_id' => $entry['entry_type'] === 'template_slide' ? $entry['slide_template_slide_id'] : null,
                    'slide_source_id' => $entry['entry_type'] === 'source' ? $entry['slide_source_id'] : null,
                    'slot_key' => $entry['slot_key'] ?? null,
                    'auto' => (bool) ($entry['auto'] ?? false),
                ]);
            }
        });

        $deck->markStale();
    }

    /**
     * Pass either a flat list of titles (the short form) or titles per slot key.
     *
     * @param  list<string>|null  $titles
     * @param  array<string, list<string>>|null  $slots
     * @return array{service: Service, deck: ServiceDeck, unmatched: array<string, list<string>>, notes: list<string>}
     *
     * @throws InvalidArgumentException
     */
    public function upsert(Team $team, string $date, string $templateName, ?array $titles = null, ?array $slots = null, string $source = 'manual', ?string $serviceTitle = null): array
    {
        $template = $this->template($team, $templateName);
        $service = $this->service($team, $date, $serviceTitle, $source);

        if (! $service->wasRecentlyCreated && filled($serviceTitle) && $service->title !== trim($serviceTitle)) {
            $service->update(['title' => trim($serviceTitle)]);
        }

        $deck = $service->decks()->where('slide_template_id', $template->id)->first()
            ?? $this->createDeck($service, $template);

        $result = $slots !== null
            ? $this->fillSlots($deck, $slots)
            : $this->fill($deck, $titles ?? []);

        return ['service' => $service, 'deck' => $deck->fresh(), ...$result];
    }

    public function template(Team $team, string $name): SlideTemplate
    {
        $template = SlideTemplate::query()->forTeam($team)->where('name', $name)->first()
            ?? SlideTemplate::query()->forTeam($team)->where('default_kind', $name)->first();

        if (! $template) {
            throw new InvalidArgumentException("No template is named \"{$name}\".");
        }

        return $template;
    }

    /**
     * Only items in the given file format are considered, since a deck merges files of one format.
     * When a kind is given, an exact title of that kind wins, then an exact title of any kind, then a close title.
     */
    public function match(Team $team, string $title, ?string $format = null, ?string $kind = null): ?SlideSource
    {
        $needle = Str::lower(trim($title));
        $sources = $this->library[$team->id] ??= SlideSource::query()->forTeam($team)->where('archived', false)->with('aliases')->get();

        if ($format !== null) {
            $sources = $sources->where('original_format', $format);
        }

        $preferred = $kind !== null && $kind !== '' ? $sources->where('kind', $kind) : null;

        if ($preferred !== null && ($match = $this->exact($preferred, $needle))) {
            return $match;
        }

        if ($match = $this->exact($sources, $needle)) {
            return $match;
        }

        if ($preferred !== null && ($match = $this->closest($preferred, $needle))) {
            return $match;
        }

        return $this->closest($sources, $needle);
    }

    /**
     * @param  Collection<int, SlideSource>  $sources
     */
    private function exact(Collection $sources, string $needle): ?SlideSource
    {
        return $sources->first(fn (SlideSource $source) => Str::lower($source->title) === $needle)
            ?? $sources->first(fn (SlideSource $source) => $source->aliases->contains(fn ($alias) => Str::lower($alias->alias) === $needle));
    }

    /**
     * @param  Collection<int, SlideSource>  $sources
     */
    private function closest(Collection $sources, string $needle): ?SlideSource
    {
        $best = null;
        $bestScore = 0;

        foreach ($sources as $source) {
            similar_text($needle, Str::lower($source->title), $percent);

            if ($percent > $bestScore) {
                $best = $source;
                $bestScore = $percent;
            }
        }

        return $bestScore >= 80 ? $best : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentEntries(ServiceDeck $deck): array
    {
        return array_values($deck->entries()->get()->map(fn ($entry) => [
            'entry_type' => $entry->entry_type,
            'slide_template_slide_id' => $entry->slide_template_slide_id,
            'slide_source_id' => $entry->slide_source_id,
            'slot_key' => $entry->slot_key,
            'auto' => $entry->auto,
        ])->all());
    }

    /**
     * Swaps a slot's entries in place. An empty slot goes after the nearest earlier preset step
     * still in the deck, or before the nearest later one.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $slot
     * @param  list<array<string, mixed>>  $replacement
     * @return list<array<string, mixed>>
     */
    private function replaceSlot(array $entries, array $steps, array $slot, array $replacement): array
    {
        $first = null;
        foreach ($entries as $index => $entry) {
            if ($entry['slot_key'] === $slot['key']) {
                $first ??= $index;
            }
        }

        $kept = array_values(array_filter($entries, fn (array $entry) => $entry['slot_key'] !== $slot['key']));

        if ($first !== null) {
            array_splice($kept, $first, 0, $replacement);

            return $kept;
        }

        $stepIndex = array_search($slot['key'], array_map(fn ($step) => $step['key'] ?? null, $steps), true);

        for ($i = $stepIndex - 1; $i >= 0; $i--) {
            $at = $this->lastIndexForStep($kept, $steps[$i]);
            if ($at !== null) {
                array_splice($kept, $at + 1, 0, $replacement);

                return $kept;
            }
        }

        for ($i = $stepIndex + 1; $i < count($steps); $i++) {
            $at = $this->firstIndexForStep($kept, $steps[$i]);
            if ($at !== null) {
                array_splice($kept, $at, 0, $replacement);

                return $kept;
            }
        }

        return [...$kept, ...$replacement];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $step
     */
    private function lastIndexForStep(array $entries, array $step): ?int
    {
        $found = null;
        foreach ($entries as $index => $entry) {
            if ($this->entryMatchesStep($entry, $step)) {
                $found = $index;
            }
        }

        return $found;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $step
     */
    private function firstIndexForStep(array $entries, array $step): ?int
    {
        foreach ($entries as $index => $entry) {
            if ($this->entryMatchesStep($entry, $step)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $step
     */
    private function entryMatchesStep(array $entry, array $step): bool
    {
        return $step['type'] === 'slot'
            ? $entry['slot_key'] === $step['key']
            : $entry['slot_key'] === null && $entry['entry_type'] === 'template_slide' && $entry['slide_template_slide_id'] === $step['slide_id'];
    }
}
