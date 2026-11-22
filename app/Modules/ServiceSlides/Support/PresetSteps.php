<?php

namespace App\Modules\ServiceSlides\Support;

use App\Models\Team;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Models\SlideTemplateSlide;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PresetSteps
{
    public function __construct(private Preset $preset, private ServicePlanner $planner) {}

    /**
     * @return array<string, mixed>
     */
    public function present(SlideTemplate $template): array
    {
        $template->load('slides');
        $slides = $this->slides($template);
        $byId = $slides->keyBy('id');
        $steps = $this->preset->steps($template);
        $titles = $this->titles($steps);
        $presented = [];

        foreach ($steps as $step) {
            if ($step['type'] === 'template_slide') {
                $slide = $byId->get($step['slide_id']);

                if ($slide instanceof SlideTemplateSlide) {
                    $presented[] = ['type' => 'slide', 'label' => $slide->label];
                }

                continue;
            }

            $separator = $byId->get($step['separator_slide_id'] ?? null);
            $presented[] = [
                'type' => 'slot',
                'key' => $step['key'],
                'label' => $step['label'],
                'kind' => $step['kind'],
                'repeat' => (bool) $step['repeat'],
                'separator' => $separator instanceof SlideTemplateSlide ? $separator->label : null,
                'defaults' => $this->defaultTitles($titles, $step['default_source_ids'] ?? []),
            ];
        }

        return [
            'id' => $template->id,
            'name' => $template->name,
            'format' => $template->format,
            'default_kind' => $template->default_kind,
            'is_history_tracked' => $template->is_history_tracked,
            'preset_saved' => $template->preset !== null,
            'slides' => $slides->map(fn (SlideTemplateSlide $slide) => [
                'index' => $slide->slide_index,
                'label' => $slide->label,
                'role' => $slide->role,
            ])->all(),
            'steps' => $presented,
            'slots' => collect($steps)->where('type', 'slot')->map(fn (array $slot) => [
                'key' => $slot['key'],
                'label' => $slot['label'],
                'kind' => $slot['kind'],
                'repeat' => (bool) $slot['repeat'],
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, mixed>  $steps
     *
     * @throws InvalidArgumentException
     * @throws ValidationException
     */
    public function store(SlideTemplate $template, array $steps): void
    {
        $template->load('slides');
        $template->update(['preset' => $this->preset->normalize($this->translate($template, $steps), $template)]);
    }

    public function errorMessage(InvalidArgumentException|ValidationException $exception): string
    {
        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())->flatten()->first();

            return is_string($message) ? $message : 'The preset is invalid.';
        }

        return $exception->getMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $required): array
    {
        return [
            'steps' => [$required ? 'present' : 'nullable', 'array'],
            'steps.*' => ['array'],
            'steps.*.type' => ['required', 'in:slide,slot'],
            'steps.*.label' => ['nullable', 'string', 'max:255'],
            'steps.*.key' => ['nullable', 'string', 'max:50'],
            'steps.*.kind' => ['nullable', 'string'],
            'steps.*.repeat' => ['nullable', 'boolean'],
            'steps.*.separator' => ['nullable', 'string', 'max:255'],
            'steps.*.defaults' => ['nullable', 'array'],
            'steps.*.defaults.*' => ['string'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return Collection<int, string>
     */
    private function titles(array $steps): Collection
    {
        $ids = [];

        foreach ($steps as $step) {
            if (($step['type'] ?? null) === 'slot') {
                array_push($ids, ...array_map('intval', $step['default_source_ids'] ?? []));
            }
        }

        if ($ids === []) {
            return collect();
        }

        return SlideSource::query()->whereIn('id', array_unique($ids))->pluck('title', 'id');
    }

    /**
     * @param  array<int, mixed>  $steps
     * @return list<array<string, mixed>>
     */
    private function translate(SlideTemplate $template, array $steps): array
    {
        $translated = [];

        foreach (array_values($steps) as $step) {
            if (! is_array($step) || ! in_array($step['type'] ?? null, ['slide', 'slot'], true)) {
                throw new InvalidArgumentException('Each step is a slide or a slot.');
            }

            if ($step['type'] === 'slide') {
                $label = trim((string) ($step['label'] ?? ''));

                if ($label === '') {
                    throw new InvalidArgumentException('A slide step needs a label.');
                }

                $translated[] = [
                    'type' => 'template_slide',
                    'slide_id' => $this->slideId($template, $label),
                ];

                continue;
            }

            $key = trim((string) ($step['key'] ?? ''));
            $label = trim((string) ($step['label'] ?? ''));

            if ($key === '' || $label === '') {
                throw new InvalidArgumentException('A slot step needs a key and a label.');
            }

            $separator = trim((string) ($step['separator'] ?? ''));
            $translated[] = [
                'type' => 'slot',
                'key' => $key,
                'label' => $label,
                'kind' => filled($step['kind'] ?? null) ? $step['kind'] : null,
                'repeat' => filter_var($step['repeat'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'separator_slide_id' => $separator !== '' ? $this->slideId($template, $separator) : null,
                'default_source_ids' => $this->defaultIds($template, array_values($step['defaults'] ?? [])),
            ];
        }

        return $translated;
    }

    private function slideId(SlideTemplate $template, string $label): int
    {
        $needle = Str::lower(trim($label));
        $slides = $this->slides($template);
        $matches = $slides->filter(fn (SlideTemplateSlide $slide) => Str::lower($slide->label) === $needle)->values();

        $match = $matches->first();

        if ($matches->count() === 1 && $match instanceof SlideTemplateSlide) {
            return $match->id;
        }

        $list = $slides->map(fn (SlideTemplateSlide $slide) => $slide->slide_index.': '.$slide->label)->implode(', ');
        $reason = $matches->isEmpty()
            ? "No slide is labeled \"{$label}\"."
            : "More than one slide is labeled \"{$label}\".";

        throw new InvalidArgumentException($reason.' Slides: '.$list);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    private function defaultIds(SlideTemplate $template, array $titles): array
    {
        $team = $template->team;

        if (! $team instanceof Team) {
            throw new InvalidArgumentException('This template has no congregation.');
        }

        $unmatched = [];
        $ids = [];

        foreach ($titles as $title) {
            $source = $this->planner->match($team, $title, $template->format);

            if ($source === null) {
                $unmatched[] = $title;

                continue;
            }

            $ids[] = $source->id;
        }

        if ($unmatched !== []) {
            throw new InvalidArgumentException('These default titles are not in the library: '.implode(', ', $unmatched).'.');
        }

        return $ids;
    }

    /**
     * @param  Collection<int, string>  $titles
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    private function defaultTitles(Collection $titles, array $ids): array
    {
        $defaults = [];

        foreach (array_map('intval', $ids) as $id) {
            $title = $titles->get($id);

            if (is_string($title)) {
                $defaults[] = $title;
            }
        }

        return $defaults;
    }

    /**
     * @return Collection<int, SlideTemplateSlide>
     */
    private function slides(SlideTemplate $template): Collection
    {
        $slides = [];

        foreach ($template->slides as $slide) {
            if ($slide->missing) {
                continue;
            }

            $slides[$slide->slide_index] = $slide;
        }

        ksort($slides);

        return collect(array_values($slides));
    }
}
